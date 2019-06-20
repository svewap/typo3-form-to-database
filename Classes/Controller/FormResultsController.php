<?php
/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Controller;

use DateTime;
use DateTimeZone;
use Exception;
use Lavitto\FormToDatabase\Domain\Model\FormResult;
use Lavitto\FormToDatabase\Domain\Repository\FormResultRepository;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Form\Controller\FormManagerController;

/**
 * Class FormResultsController
 *
 * @package Lavitto\FormToDatabase\Controller
 */
class FormResultsController extends FormManagerController
{

    /**
     * CSV Linebreak
     */
    protected const CSV_LINEBREAK = "\n";

    /**
     * CSV Delimiter
     */
    protected const CSV_DELIMITER = ',';

    /**
     * CSV Text Enclosure
     */
    protected const CSV_ENCLOSURE = '"';

    /**
     * The FormResultRepository
     *
     * @var FormResultRepository
     */
    protected $formResultRepository;

    /**
     * Injects the FormResultRepository
     *
     * @param FormResultRepository $formResultRepository
     */
    public function injectFormResultRepository(FormResultRepository $formResultRepository): void
    {
        $this->formResultRepository = $formResultRepository;
    }

    /**
     * Displays the Form Overview
     *
     * @internal
     */
    public function indexAction(): void
    {
        $this->registerDocheaderButtons();
        $this->view->getModuleTemplate()->setModuleName($this->request->getPluginName() . '_' . $this->request->getControllerName());
        $this->view->getModuleTemplate()->setFlashMessageQueue($this->controllerContext->getFlashMessageQueue());
        $this->view->assign('forms', $this->getAvailableFormDefinitions());
        $this->assignDefaults();
    }

    /**
     * Shows the results of a form
     *
     * @param string $formPersistenceIdentifier
     */
    public function showAction(string $formPersistenceIdentifier): void
    {
        $this->view->getModuleTemplate()->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/Modal');

        $formDefinition = $this->formPersistenceManager->load($formPersistenceIdentifier);
        $formInformation = $this->getFormInformation($formDefinition['renderables']);
        $formResults = $this->formResultRepository->findByFormPersistenceIdentifier($formPersistenceIdentifier);

        $this->registerDocheaderButtons($formPersistenceIdentifier, $formResults->count() > 0);

        $this->view->assignMultiple([
            'formDefinition' => $formDefinition,
            'formInformation' => $formInformation,
            'formResults' => $formResults,
            'formFields' => $this->getFormFields($formResults, $formInformation)
        ]);

        $this->assignDefaults();
    }

    /**
     * Downloads the current results list as CSV
     *
     * @throws NoSuchArgumentException
     * @throws Exception
     */
    public function downloadCsvAction(): void
    {
        $formPersistenceIdentifier = $this->request->getArgument('formPersistenceIdentifier');
        $csvContent = $this->getCsvContent($formPersistenceIdentifier);
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename="' . $this->getCsvFileName($formPersistenceIdentifier) . '";');
        header('Content-Length: ' . strlen($csvContent));
        echo $csvContent;
        die;
    }

    /**
     * List all formDefinitions which can be loaded form persistence manager.
     * Enrich this data by a the number of results.
     *
     * @return array
     */
    protected function getAvailableFormDefinitions(): array
    {
        $availableFormDefinitions = [];
        foreach ($this->formPersistenceManager->listForms() as $formDefinition) {
            $formDefinition['numberOfResults'] = $this->formResultRepository->countByFormPersistenceIdentifier($formDefinition['persistenceIdentifier']);
            $availableFormDefinitions[] = $formDefinition;
        }
        return $availableFormDefinitions;
    }

    /**
     * Gets an array with all form labels by the form definition
     *
     * @param array $renderables
     * @param array $formFields
     * @return array
     */
    protected function getFormInformation(array $renderables, array $formFields = []): array
    {
        foreach ($renderables as $renderable) {
            $formFields[$renderable['identifier']] = [
                'label' => $renderable['label'],
                'type' => $renderable['type']
            ];
            if ($renderable['renderables'] !== null && !empty($renderable['renderables'])) {
                $formFields = $this->getFormInformation($renderable['renderables'], $formFields);
            }
        }
        return $formFields;
    }

    /**
     * Gets and returns the FormFields
     *
     * @param QueryResultInterface $formResults
     * @param array $formInformation
     * @return array
     */
    protected function getFormFields(QueryResultInterface $formResults, array $formInformation): array
    {
        $formHeaders = [];
        /** @var FormResult $formResult */
        foreach ($formResults->toArray() as $formResult) {
            foreach ($formResult->getResultAsArray() as $fieldName => $fieldValue) {
                if (isset($formInformation[$fieldName]) && !in_array($fieldName, $formHeaders, true)) {
                    $formHeaders[] = $fieldName;
                }
            }
        }
        return $formHeaders;
    }

    /**
     * Register document header buttons
     *
     * @param string|null $formPersistenceIdentifier
     * @param bool $showCsvDownload
     */
    protected function registerDocheaderButtons(
        string $formPersistenceIdentifier = null,
        bool $showCsvDownload = false
    ): void {
        /** @var ButtonBar $buttonBar */
        $buttonBar = $this->view->getModuleTemplate()->getDocHeaderComponent()->getButtonBar();
        $currentRequest = $this->request;
        $moduleName = $currentRequest->getPluginName();
        $getVars = $this->request->getArguments();

        if ($this->request->getControllerActionName() === 'show') {
            $backFormButton = $buttonBar->makeLinkButton()
                ->setHref($this->getModuleUrl('web_FormToDatabaseFormresults'))
                ->setTitle($this->getLanguageService()->sL('LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:show.buttons.backlink'))
                ->setShowLabelText(true)
                ->setIcon($this->view->getModuleTemplate()->getIconFactory()->getIcon('actions-view-go-back',
                    Icon::SIZE_SMALL));
            $buttonBar->addButton($backFormButton, ButtonBar::BUTTON_POSITION_LEFT);

            if ($formPersistenceIdentifier !== null && $showCsvDownload === true) {
                $downloadCsvFormButton = $buttonBar->makeLinkButton()
                    ->setHref($this->getModuleUrl('web_FormToDatabaseFormresults', [
                        'tx_formtodatabase_web_formtodatabaseformresults' => [
                            'formPersistenceIdentifier' => $formPersistenceIdentifier,
                            'action' => 'downloadCsv',
                            'controller' => 'FormResults'
                        ]
                    ]))
                    ->setTitle($this->getLanguageService()->sL('LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:show.buttons.download_csv'))
                    ->setShowLabelText(true)
                    ->setIcon($this->view->getModuleTemplate()->getIconFactory()->getIcon('actions-download',
                        Icon::SIZE_SMALL));
                $buttonBar->addButton($downloadCsvFormButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
            }
        }

        // Reload title
        if (version_compare(VersionNumberUtility::getNumericTypo3Version(), '9.5', '>=') === true) {
            $reloadTitle = $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload');
        } else {
            $reloadTitle = $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.reload');
        }
        $reloadButton = $buttonBar->makeLinkButton()
            ->setHref(GeneralUtility::getIndpEnv('REQUEST_URI'))
            ->setTitle($reloadTitle)
            ->setIcon($this->view->getModuleTemplate()->getIconFactory()->getIcon('actions-refresh', Icon::SIZE_SMALL));
        $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT);

        // Shortcut
        $mayMakeShortcut = $this->getBackendUser()->mayMakeShortcut();
        if ($mayMakeShortcut) {
            $extensionName = $currentRequest->getControllerExtensionName();
            if (count($getVars) === 0) {
                $modulePrefix = strtolower('tx_' . $extensionName . '_' . $moduleName);
                $getVars = ['id', 'route', $modulePrefix];
            }
            $shortcutButton = $buttonBar->makeShortcutButton()
                ->setModuleName($moduleName)
                ->setDisplayName($this->getLanguageService()->sL('LLL:EXT:form/Resources/Private/Language/Database.xlf:module.shortcut_name'))
                ->setGetVariables($getVars);
            $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
        }
    }

    /**
     * Generates and returns the csv content by a given formPersistenceIdentifier
     *
     * @param string $formPersistenceIdentifier
     * @return string
     */
    protected function getCsvContent(string $formPersistenceIdentifier): string
    {
        $csvContent = [];

        $formResults = $this->formResultRepository->findByFormPersistenceIdentifier($formPersistenceIdentifier);
        $formDefinition = $this->formPersistenceManager->load($formPersistenceIdentifier);
        $formInformation = $this->getFormInformation($formDefinition['renderables']);
        $formFields = $this->getFormFields($formResults, $formInformation);

        $header = [
            self::CSV_ENCLOSURE . $this->getLanguageService()->sL('LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:show.crdate') . self::CSV_ENCLOSURE
        ];
        foreach ($formFields as $formField) {
            $header[] = self::CSV_ENCLOSURE . $formInformation[$formField]['label'] . self::CSV_ENCLOSURE;
        }
        $csvContent[] = implode(self::CSV_DELIMITER, $header);

        /** @var FormResult $formResult */
        foreach ($formResults as $i => $formResult) {
            $resultsArray = $formResult->getResultAsArray();
            $content = [
                self::CSV_ENCLOSURE . $formResult->getCrdate()->format($this->getDateFormat() . ' ' . $this->getTimeFormat()) . self::CSV_ENCLOSURE
            ];
            foreach ($formFields as $formField) {
                $fieldValue = $resultsArray[$formField] ?? '';
                $cleanFieldValue = trim(str_replace(self::CSV_ENCLOSURE, '\\' . self::CSV_ENCLOSURE, $fieldValue));
                $content[] = self::CSV_ENCLOSURE . $cleanFieldValue . self::CSV_ENCLOSURE;
            }
            $csvContent[] = implode(self::CSV_DELIMITER, $content);
        }

        return implode(self::CSV_LINEBREAK, $csvContent);
    }

    /**
     * Creates and returns the csv filename by a given formPersistenceIdentifier
     *
     * @param string $formPersistenceIdentifier
     * @return string
     * @throws Exception
     */
    protected function getCsvFilename(string $formPersistenceIdentifier): string
    {
        /** @var LocalDriver $localDriver */
        $localDriver = $this->objectManager->get(LocalDriver::class);
        $dateTime = new DateTime('now', $this->getValidTimezone());
        $filename = $dateTime->format($this->getDateFormat() . ' ' . $this->getTimeFormat());
        $filename .= '_' . preg_replace('/\.form\.yaml$/', '', basename($formPersistenceIdentifier)) . '.csv';
        return $localDriver->sanitizeFileName($filename);
    }

    /**
     * Returns a valid DateTimeZone with fallback function TYPO3_CONF_VARS > default_timezone > UTC
     *
     * @return DateTimeZone
     */
    protected function getValidTimezone(): DateTimeZone
    {
        if (in_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'], timezone_identifiers_list(), true) === true) {
            $validTimeZone = $GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'];
        } elseif (in_array(date_default_timezone_get(), timezone_identifiers_list(), true) === true) {
            $validTimeZone = date_default_timezone_get();
        } else {
            $validTimeZone = DateTimeZone::UTC;
        }
        return new DateTimeZone($validTimeZone);
    }

    /**
     * Assigns the default variables
     */
    protected function assignDefaults(): void
    {
        $this->view->assignMultiple([
            'dateFormat' => $this->getDateFormat(),
            'timeFormat' => $this->getTimeFormat()
        ]);
    }

    /**
     * Returns the date format, configured in TYPO3_CONF_VARS with fallback-option
     *
     * @return string
     */
    protected function getDateFormat(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd-m-y';
    }

    /**
     * Returns the time format, configured in TYPO3_CONF_VARS with fallback-option
     *
     * @return string
     */
    protected function getTimeFormat(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i';
    }
}
