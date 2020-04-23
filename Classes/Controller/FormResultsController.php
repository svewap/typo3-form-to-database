<?php /** @noinspection PhpUndefinedMethodInspection */
/** @noinspection AdditionOperationOnArraysInspection */
/** @noinspection PhpInternalEntityUsedInspection */

/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Controller;

use DateTime;
use Exception;
use Lavitto\FormToDatabase\Domain\Model\FormResult;
use Lavitto\FormToDatabase\Domain\Repository\FormResultRepository;
use Lavitto\FormToDatabase\Helpers\MiscHelper;
use Lavitto\FormToDatabase\Utility\ExtConfUtility;
use Lavitto\FormToDatabase\Utility\FormDefinitionUtility;
use Lavitto\FormToDatabase\Utility\FormValueUtility;
use PDO;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException;
use TYPO3\CMS\Form\Controller\FormManagerController;
use TYPO3\CMS\Form\Domain\Exception\RenderingException;
use TYPO3\CMS\Form\Domain\Factory\ArrayFormFactory;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Model\FormElements\AbstractFormElement;
use TYPO3\CMS\Form\Slot\FilePersistenceSlot;

/**
 * Class FormResultsController
 *
 * @package Lavitto\FormToDatabase\Controller
 */
class FormResultsController extends FormManagerController
{

    /**
     *
     */
    public const SIGNAL_FORMSRESULT_SHOW_ACTION = 'showAction';

    /**
     *
     */
    public const SIGNAL_FORMSRESULT_DOWNLOAD_CSV_ACTION = 'downloadCsvAction';

    /**
     *
     */
    public const SIGNAL_FORMSRESULT_DELETE_FORM_RESULT_ACTION = 'deleteFormResultAction';

    /**
     * CSV Linebreak
     */
    protected const CSV_LINEBREAK = "\n";

    /**
     * CSV Text Enclosure
     */
    protected const CSV_ENCLOSURE = '"';

    /**
     * @var ExtConfUtility
     */
    protected $extConfUtility;

    /**
     * The FormResultRepository
     *
     * @var FormResultRepository
     */
    protected $formResultRepository;

    /**
     * @var Dispatcher
     */
    protected $signalSlotDispatcher;

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
     * Injects the ExtConfUtility
     *
     * @param ExtConfUtility $extConfUtility
     */
    public function injectExtConfUtility(ExtConfUtility $extConfUtility): void
    {
        $this->extConfUtility = $extConfUtility;
    }

    /**
     * Initialize Show Action
     */
    public function initializeShowAction(): void
    {
        $this->getPageRenderer()->addCssFile(
            'EXT:form_to_database/Resources/Public/Css/ShowPrintStyles.min.css',
            'stylesheet',
            'print'
        );
    }

    /**
     * Inject SignalSlotDispatcher
     *
     * @param Dispatcher $signalSlotDispatcher
     * @noinspection SenselessMethodDuplicationInspection
     */
    public function injectSignalSlotDispatcher(Dispatcher $signalSlotDispatcher): void
    {
        $this->signalSlotDispatcher = $signalSlotDispatcher;
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
        $availableFormDefinitions = $this->getAvailableFormDefinitions();
        $this->view->assign('forms', $availableFormDefinitions);
        $this->view->assign('deletedForms', $this->getDeletedFormDefinitions($availableFormDefinitions));
        $this->assignDefaults();
    }

    /**
     * Shows the results of a form
     *
     * @param string $formPersistenceIdentifier
     * @throws InvalidQueryException
     * @throws RenderingException
     * @noinspection PhpUndefinedMethodInspection
     * @noinspection PhpUnused
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
        $formDefinition = $this->getFormDefinitionObject($formPersistenceIdentifier, true);
        $formRenderables = $this->getFormRenderables($formDefinition);

        $this->emitSignal(self::SIGNAL_FORMSRESULT_SHOW_ACTION, [
            'formPersistenceIdentifier' => $formPersistenceIdentifier,
            'formResults' => $formResults,
            'formDefinition' => $formDefinition,
            '$formRenderables' => $formRenderables
        ]);

        $this->registerDocheaderButtons($formPersistenceIdentifier, $formResults->count() > 0);
        $this->view->assignMultiple([
            'formResults' => $formResults,
            'formDefinition' => $formDefinition,
            'formRenderables' => $formRenderables,
            'formPersistenceIdentifier' => $formPersistenceIdentifier
        ]);
        $this->assignDefaults();
    }

    /**
     * Downloads the current results list as CSV
     *
     * @throws NoSuchArgumentException
     * @throws Exception
     * @todo Add more charsets?
     */
    public function downloadCsvAction(): void
    {
        $charset = 'UTF-8';
        $formPersistenceIdentifier = $this->request->getArgument('formPersistenceIdentifier');
        $csvContent = "\xEF\xBB\xBF" . $this->getCsvContent($formPersistenceIdentifier);
        header('Content-Type: application/csv; charset=' . $charset);
        header('Content-Disposition: attachment; filename="' . $this->getCsvFileName($formPersistenceIdentifier) . '";');
        header('Content-Length: ' . strlen($csvContent));
        echo $csvContent;
        die;
    }

    /**
     * Deletes a form result and forwards to the show action
     *
     * @param FormResult $formResult
     * @throws IllegalObjectTypeException
     * @throws RenderingException
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     */
    public function deleteFormResultAction(FormResult $formResult): void
    {
        $formPersistenceIdentifier = $formResult->getFormPersistenceIdentifier();
        $this->formResultRepository->remove($formResult);
        $formDefinition = $this->getFormDefinitionObject($formPersistenceIdentifier);

        $this->emitSignal(self::SIGNAL_FORMSRESULT_DELETE_FORM_RESULT_ACTION, [
            $formPersistenceIdentifier,
            $formResult,
            $formDefinition,
            $this->getFormRenderables($formDefinition)
        ]);
        $this->redirect('show', null, null, ['formPersistenceIdentifier' => $formPersistenceIdentifier]);
    }

    /**
     * @param string $formDefinitionPath
     * @param string $formIdentifier
     * @throws IllegalObjectTypeException
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     * @throws UnknownObjectException
     * @noinspection PhpParamsInspection
     */
    public function unDeleteFormDefinitionAction(string $formDefinitionPath, string $formIdentifier): void
    {
        /** @var FilePersistenceSlot $formPersistenceSlot */
        $formPersistenceSlot = GeneralUtility::makeInstance(FilePersistenceSlot::class);
        $formPersistenceSlot->allowInvocation(
            FilePersistenceSlot::COMMAND_FILE_MOVE,
            str_replace('.deleted', '', $formDefinitionPath)
        );
        $resourceFactory = ResourceFactory::getInstance();

        /** @var File $file */
        $file = $resourceFactory->getFileObjectFromCombinedIdentifier($formDefinitionPath);

        if ($file !== null) {
            $filename = "{$formIdentifier}.form.yaml";
            $newCombinedIdentifier = $file->moveTo($file->getParentFolder(), $filename)->getCombinedIdentifier();
            $results = $this->formResultRepository->findByFormIdentifier($formIdentifier);
            /** @var FormResult $result */
            foreach ($results as $result) {
                $result->setFormPersistenceIdentifier($newCombinedIdentifier);
                $this->formResultRepository->update($result);
            }
        }

        $this->redirect('index');
    }

    /**
     * @throws NoSuchArgumentException
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     */
    public function updateItemListSelectAction(): void
    {
        $formPersistenceIdentifier = $this->request->getArgument('formPersistenceIdentifier');
        $formDefinition = $this->getFormDefinition($formPersistenceIdentifier);
        /** @var FormDefinitionUtility $formDefinitionUtility */
        $formDefinitionUtility = GeneralUtility::makeInstance(FormDefinitionUtility::class);
        $formDefinitionUtility->addFieldStateIfDoesNotExist($formDefinition);

        $fieldSelectedState = $this->request->getArgument('field');
        if (isset($formDefinition['renderingOptions']['fieldState'])) {
            foreach ($formDefinition['renderingOptions']['fieldState'] as $fieldKey => &$fieldData) {
                $fieldData['renderingOptions']['listView'] = $fieldSelectedState[$fieldKey] ? 1 : 0;
            }
            unset($fieldData);
        }

        $this->formPersistenceManager->save($formPersistenceIdentifier, $formDefinition);
        $this->redirect('show', null, null,
            ['formPersistenceIdentifier' => $this->request->getArgument('formPersistenceIdentifier')]);
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
     * List all representations of deleted formDefinitions which can be found in FormResults but not from persistence manager.
     * Enrich this data by a the number of results.
     *
     * @param array $availableFormDefinitions
     * @return array
     */
    protected function getDeletedFormDefinitions(array $availableFormDefinitions): array
    {
        $accessibleDeletedFormDefinitions = [];
        $storageFolders = $this->formPersistenceManager->getAccessibleFormStorageFolders();
        /** @var FileExtensionFilter $filter */
        $filter = GeneralUtility::makeInstance(FileExtensionFilter::class);
        $filter->setAllowedFileExtensions(['deleted']);
        foreach ($storageFolders as $storageFolder) {
            $storageFolder->setFileAndFolderNameFilters([[$filter, 'filterFileList']]);
            $accessibleDeletedFormDefinitions += $storageFolder->getFiles();
        }
        $accessibleDeletedFormDefinitions = array_map(static function ($val) {
            $val = $val->getCombinedIdentifier();
            return $val;
        }, $accessibleDeletedFormDefinitions, []);
        $persistenceIdentifier = array_column($availableFormDefinitions, 'persistenceIdentifier') ?: [''];

        $webMounts = MiscHelper::getWebMounts();
        //plugins that user currently has access to
        $pluginUids = MiscHelper::getPluginUids($webMounts);
        //site admins
        $siteIdentifiers = MiscHelper::getSiteIdentifiersFromRootPids($webMounts);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_formtodatabase_domain_model_formresult');
        $result = $queryBuilder
            ->select('form_persistence_identifier', 'form_identifier')
            ->addSelectLiteral($queryBuilder->expr()->count('form_identifier', 'count'))
            ->from('tx_formtodatabase_domain_model_formresult')
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->in('form_plugin_uid',
                        $queryBuilder->createNamedParameter($pluginUids ?? [''], Connection::PARAM_STR_ARRAY)),
                    $queryBuilder->expr()->in('site_identifier',
                        $queryBuilder->createNamedParameter($siteIdentifiers ?? [''], Connection::PARAM_STR_ARRAY)),
                    //Backward compatibility with old data
                    $queryBuilder->expr()->andX(
                        $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter('')),
                        $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, PDO::PARAM_INT))
                    )
                ),
                $queryBuilder->expr()->notIn('form_persistence_identifier',
                    $queryBuilder->createNamedParameter($persistenceIdentifier, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->in('form_persistence_identifier',
                    $queryBuilder->createNamedParameter($accessibleDeletedFormDefinitions, Connection::PARAM_STR_ARRAY))

            )->groupBy('tx_formtodatabase_domain_model_formresult.form_persistence_identifier',
                'tx_formtodatabase_domain_model_formresult.form_identifier')
            ->execute()->fetchAll();

        array_walk($result, static function (&$val) {
            $val['name'] = $val['identifier'] = preg_replace("/.*\/(.*)-([a-z0-9]{13}).form.yaml.deleted/", '$1',
                $val['form_persistence_identifier']);
            $val['persistenceIdentifier'] = $val['form_persistence_identifier'];
        });
        return $result;
    }

    /**
     * Gets a form definition by a persistence form identifier
     *
     * @param string $formPersistenceIdentifier
     * @param bool $useFieldStateDataAsRenderables
     * @return array
     */
    protected function getFormDefinition(
        string $formPersistenceIdentifier,
        $useFieldStateDataAsRenderables = false
    ): array {
        $configuration = $this->formPersistenceManager->load($formPersistenceIdentifier);

        if ($useFieldStateDataAsRenderables) {
            //Ensure that fieldState exists
            /** @var FormDefinitionUtility $formDefinitionUtility */
            $formDefinitionUtility = GeneralUtility::makeInstance(FormDefinitionUtility::class);
            $formDefinitionUtility->addFieldStateIfDoesNotExist($configuration, true);

            //Use fieldState as renderables instead of renderables
            unset($configuration['renderables'][0]['renderables']);
            $configuration['renderables'][0]['renderables'] = array_values($configuration['renderingOptions']['fieldState']);
            $configuration['renderables'] = array_intersect_key($configuration['renderables'], [0 => 1]);
        }
        return $configuration;
    }

    /**
     * Gets a form definition by a persistence form identifier
     *
     * @param string $formPersistenceIdentifier
     * @param bool $useFieldStateDataAsRenderables
     * @return FormDefinition
     * @throws RenderingException
     */
    protected function getFormDefinitionObject(
        string $formPersistenceIdentifier,
        $useFieldStateDataAsRenderables = false
    ): FormDefinition {
        $configuration = $this->getFormDefinition($formPersistenceIdentifier, $useFieldStateDataAsRenderables);
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
        $csvDelimiter = $this->extConfUtility->getConfig('csvDelimiter') ?? ';';
        $csvContent = [];

        $formResults = $this->formResultRepository->findByFormPersistenceIdentifier($formPersistenceIdentifier);
        $formDefinition = $this->getFormDefinitionObject($formPersistenceIdentifier, true);
        $formRenderables = $this->getFormRenderables($formDefinition);

        $this->emitSignal(self::SIGNAL_FORMSRESULT_DOWNLOAD_CSV_ACTION, [
            $formPersistenceIdentifier,
            $formResults,
            $formDefinition,
            $formRenderables
        ]);

        $header = [
            self::CSV_ENCLOSURE . $this->getLanguageService()->sL('LLL:EXT:form_to_database/Resources/Private/Language/locallang_be.xlf:show.crdate') . self::CSV_ENCLOSURE
        ];

        /** @var AbstractFormElement $renderable */
        foreach ($formRenderables as $renderable) {
            $header[] = self::CSV_ENCLOSURE . $renderable->getLabel() . self::CSV_ENCLOSURE;
        }
        $csvContent[] = implode($csvDelimiter, $header);

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
            $csvContent[] = implode($csvDelimiter, $content);
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
            'timeFormat' => FormValueUtility::getTimeFormat(),
            'extConf' => $this->extConfUtility->getFullConfig()
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

    /**
     * Emits signal
     *
     * @param string $signalName name of the signal slot
     * @param array $signalArguments arguments for the signal slot
     */
    protected function emitSignal($signalName, array $signalArguments): void
    {
        try {
            $this->signalSlotDispatcher->dispatch(self::class, $signalName, $signalArguments);
        } catch (InvalidSlotException $exception) {
        } catch (InvalidSlotReturnException $exception) {
        }
    }
}
