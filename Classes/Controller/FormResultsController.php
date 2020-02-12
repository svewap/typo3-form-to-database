<?php /** @noinspection PhpInternalEntityUsedInspection */

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
use Lavitto\FormToDatabase\Utility\FormValueUtility;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Form\Controller\FormManagerController;
use TYPO3\CMS\Form\Domain\Exception\RenderingException;
use TYPO3\CMS\Form\Domain\Factory\ArrayFormFactory;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Model\FormElements\AbstractFormElement;

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
     * Initialize Show Action
     */
    public function initializeShowAction(): void
    {
        $this->getPageRenderer()->addCssFile('EXT:form_to_database/Resources/Public/Css/ShowStyles.css');
    }

    /**
     * Displays the Form Overview
     *
     * @throws InvalidQueryException
     * @internal
     * @noinspection PhpUndefinedMethodInspection
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
     * @throws InvalidQueryException
     * @throws RenderingException
     * @noinspection PhpUndefinedMethodInspection
     */
    public function showAction(string $formPersistenceIdentifier): void
    {
        $languageFile = 'LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:';
        $this->view->getModuleTemplate()->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/Modal');
        $this->view->getModuleTemplate()->getPageRenderer()->addInlineLanguageLabelArray([
            'ftd_deleteTitle' => $this->getLanguageService()->sL($languageFile . 'show.buttons.delete.title'),
            'ftd_deleteDescription' => $this->getLanguageService()->sL($languageFile . 'show.buttons.delete.description')
        ]);

        $formResults = $this->formResultRepository->findByFormPersistenceIdentifier($formPersistenceIdentifier);
        $formDefinition = $this->getFormDefinition($formPersistenceIdentifier);
        $formRenderables = $this->getFormRenderables($formDefinition);

        $this->registerDocheaderButtons($formPersistenceIdentifier, $formResults->count() > 0);
        $this->view->assignMultiple([
            'formResults' => $formResults,
            'formDefinition' => $formDefinition,
            'formRenderables' => $formRenderables
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
     * Deletes a form result and forwards to the show action
     *
     * @param FormResult $formResult
     * @throws StopActionException
     * @throws IllegalObjectTypeException
     * @throws UnsupportedRequestTypeException
     */
    public function deleteFormResultAction(FormResult $formResult): void
    {
        $formPersistenceIdentifier = $formResult->getFormPersistenceIdentifier();
        $this->formResultRepository->remove($formResult);
        $this->redirect('show', null, null, ['formPersistenceIdentifier' => $formPersistenceIdentifier]);
    }

    /**
     * List all formDefinitions which can be loaded form persistence manager.
     * Enrich this data by a the number of results.
     *
     * @return array
     * @throws InvalidQueryException
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
     * Gets a form definition by a persistence form identifier
     *
     * @param string $formPersistenceIdentifier
     * @return FormDefinition
     * @throws RenderingException
     */
    protected function getFormDefinition(string $formPersistenceIdentifier): FormDefinition
    {
        $configuration = $this->formPersistenceManager->load($formPersistenceIdentifier);
        $configuration['finishers'] = [];

        /** @var ArrayFormFactory $arrayFormFactory */
        $arrayFormFactory = $this->objectManager->get(ArrayFormFactory::class);
        return $arrayFormFactory->build($configuration);
    }

    /**
     * Gets an array of all form renderables (recursive) by a form definition
     *
     * @param FormDefinition $formDefinition
     * @return array
     */
    protected function getFormRenderables(FormDefinition $formDefinition): array
    {
        $formRenderables = [];
        /** @var AbstractFormElement $renderable */
        foreach ($formDefinition->getRenderablesRecursively() as $renderable) {
            if ($renderable instanceof AbstractFormElement) {
                $formRenderables[$renderable->getIdentifier()] = $renderable;
            }
        }
        return $formRenderables;
    }

    /**
     * Generates and returns the csv content by a given formPersistenceIdentifier
     *
     * @param string $formPersistenceIdentifier
     * @return string
     * @throws InvalidQueryException
     * @throws RenderingException
     */
    protected function getCsvContent(string $formPersistenceIdentifier): string
    {
        $csvContent = [];

        $formResults = $this->formResultRepository->findByFormPersistenceIdentifier($formPersistenceIdentifier);
        $formDefinition = $this->getFormDefinition($formPersistenceIdentifier);
        $formRenderables = $this->getFormRenderables($formDefinition);

        $header = [
            self::CSV_ENCLOSURE . $this->getLanguageService()->sL('LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:show.crdate') . self::CSV_ENCLOSURE
        ];
        /** @var AbstractFormElement $renderable */
        foreach ($formRenderables as $renderable) {
            $header[] = self::CSV_ENCLOSURE . $renderable->getLabel() . self::CSV_ENCLOSURE;
        }
        $csvContent[] = implode(self::CSV_DELIMITER, $header);

        /** @var FormResult $formResult */
        foreach ($formResults as $i => $formResult) {
            $resultsArray = $formResult->getResultAsArray();
            $content = [
                self::CSV_ENCLOSURE . $formResult->getCrdate()->format(FormValueUtility::getDateFormat() . ' ' . FormValueUtility::getTimeFormat()) . self::CSV_ENCLOSURE
            ];
            foreach ($formRenderables as $renderable) {
                $fieldValue = $resultsArray[$renderable->getIdentifier()] ?? '';
                $convertedFieldValue = FormValueUtility::convertFormValue($renderable, $fieldValue,
                    FormValueUtility::OUTPUT_TYPE_CSV);
                $cleanFieldValue = trim(str_replace(self::CSV_ENCLOSURE, '\\' . self::CSV_ENCLOSURE,
                    $convertedFieldValue));
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
        $dateTime = new DateTime('now',
            FormValueUtility::getValidTimezone((string)$GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone']));
        $filename = $dateTime->format(FormValueUtility::getDateFormat() . ' ' . FormValueUtility::getTimeFormat());
        $filename .= '_' . preg_replace('/\.form\.yaml$/', '', basename($formPersistenceIdentifier)) . '.csv';
        return $localDriver->sanitizeFileName($filename);
    }

    /**
     * Assigns the default variables
     */
    protected function assignDefaults(): void
    {
        $this->view->assignMultiple([
            'dateFormat' => FormValueUtility::getDateFormat(),
            'timeFormat' => FormValueUtility::getTimeFormat()
        ]);
    }

    /**
     * Register document header buttons
     *
     * @param string|null $formPersistenceIdentifier
     * @param bool $showCsvDownload
     * @noinspection PhpUndefinedMethodInspection
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
}
