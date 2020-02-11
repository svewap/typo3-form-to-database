<?php


namespace Lavitto\FormToDatabase\Hooks;

use Lavitto\FormToDatabase\Domain\Model\FormResult;
use Lavitto\FormToDatabase\Domain\Repository\FormResultRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\QueryResult;
use TYPO3\CMS\Form\Mvc\Configuration\YamlSource;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManager;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/**
 * Class FormHooks
 * @package Lavitto\FormToDatabase\Hooks
 */
class FormHooks
{

    protected $fieldTypesNextIdentifier = [];

    /** @var FormPersistenceManager */
    protected $formPersistenceManager;

    /** @var int $enableListViewUntilCount */
    protected $enableListViewUntilCount = 4;

    /**
     * The FormResultRepository
     *
     * @var FormResultRepository
     */
    public $formResultRepository;

    /**
     * Injects the FormResultRepository
     */
    public function initializeFormResultRepository(): void
    {
        /** @var ObjectManager $objectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->formResultRepository = $objectManager->get(FormResultRepository::class);
    }

    /**
     *
     */
    protected function initializeFormPersistenceManager() {
        /** @var FormPersistenceManagerInterface $formPersistenceManager */
        $this->formPersistenceManager = GeneralUtility::makeInstance(FormPersistenceManager::class);
        $this->formPersistenceManager->initializeObject();
        $this->formPersistenceManager->injectResourceFactory(ResourceFactory::getInstance());
        $this->formPersistenceManager->injectYamlSource(GeneralUtility::makeInstance(YamlSource::class));
    }

    /**
     * @param $formPersistenceIdentifier
     */
    public function beforeFormDelete($formPersistenceIdentifier) {
        $this->initializeFormResultRepository();
        $resourceFactory = ResourceFactory::getInstance();
        $this->initializeFormPersistenceManager();
        $yaml = $this->formPersistenceManager->load($formPersistenceIdentifier);
        $file = $resourceFactory->getFileObjectFromCombinedIdentifier($formPersistenceIdentifier);
        //New unique filename
        $newFilename = "{$yaml['identifier']}.form.yaml.deleted";
        $newCombinedIdentifier = $file->copyTo($file->getParentFolder(), $newFilename)->getCombinedIdentifier();
        /** @var QueryResult $results */
        $results = $this->formResultRepository->findByFormIdentifier($yaml['identifier']);
        /** @var FormResult $result */
        foreach ($results as $result) {
            $result->setFormPersistenceIdentifier($newCombinedIdentifier);
            $this->formResultRepository->update($result);
        }
    }

    /**
     * @param $formPersistenceIdentifier
     * @param $form
     * @return mixed
     */
    public function beforeFormCreate($formPersistenceIdentifier, $form) {
        $form['identifier'] .= '-' . uniqid();
        return $form;
    }

    /**
     * @param $formPersistenceIdentifier
     * @param $formToDuplicate
     * @return mixed
     */
    public function beforeFormDuplicate($formPersistenceIdentifier, $formToDuplicate) {
        $formToDuplicate['identifier'] = preg_replace("/(.*)-([a-z0-9]{13})/", "$1", $formToDuplicate['identifier']) . '-' . uniqid();
        return $formToDuplicate;
    }


    /**
     * Keep track of field identifiers of deleted and new fields, so that identifiers are not reused
     * @param $formPersistenceIdentifier
     * @param $formDefinition
     * @return mixed
     */
    public function beforeFormSave($formPersistenceIdentifier, $formDefinition) {
        //If no state exists - create state from current fields
        if(!isset($formDefinition['renderingOptions']['fieldState'])) {
            $formDefinition['renderingOptions']['fieldState'] = $this->getFieldsFromFormDefinition($formDefinition);
            $fieldCount = 0;
            //Mark all fields in state as not deleted
            $formDefinition['renderingOptions']['fieldState'] = array_map(function ($field) use (&$fieldCount) {
                $fieldCount++;
                $field['renderingOptions']['deleted'] = 0;
                $field['renderingOptions']['listView'] = $fieldCount <= $this->enableListViewUntilCount ? 1: 0;
                return $field;
                }, $formDefinition['renderingOptions']['fieldState'] );
        }

        //Make map of next identifier for each field type
        $this->makeNextIdentifiersMap($formDefinition['renderingOptions']['fieldState']);

        //Update fields and field state
        $formDefinition = $this->updateFormDefinition($formDefinition);

        return $formDefinition;
    }

    /**
     * @param $formDefinition
     * @return array
     */
    protected function getFieldsFromFormDefinition($formDefinition) {
        $fields = [];
        foreach ($formDefinition['renderables'] as $steps) {
            foreach ($steps['renderables'] as $field) {
                $fields[$field['identifier']] = $field;
            }
        }
        return $fields;
    }

    /**
     * Make sure that field identifiers are unique (identifiers of deleted fields are not reused)
     * Field state is saved to keep track of old fields
     * @param $formDefinition
     * @return mixed
     */
    protected function updateFormDefinition($formDefinition) {
        $fieldCount = 0;
        foreach ($formDefinition['renderables'] as &$steps) {
            foreach($steps['renderables'] as &$field) {
                $fieldCount++;
                //New field - field identifier does not exist in state
                if(!isset($formDefinition['renderingOptions']['fieldState'][$field['identifier']])) {
                    $this->updateNewFieldIdentifierAndAddToState($field, $formDefinition);
                    $this->updateStateListViewState($formDefinition, $fieldCount);
                //New field - but identifier exists(deleted field) in state
                } elseif($formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['deleted'] === 1) {
                    $this->updateNewFieldIdentifierAndAddToState($field, $formDefinition);
                    $this->updateStateListViewState($formDefinition, $fieldCount);
                //Existing field - update state
                } else {
                    $formDefinition['renderingOptions']['fieldState'][$field['identifier']] = array_merge($formDefinition['renderingOptions']['fieldState'][$field['identifier']], $field);
                }
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['active'] = 1;
            }
        }

        //Mark all fields that are found in renderables as deleted in state
        $this->updateStateDeletedState($formDefinition);

        return $formDefinition;
    }

    /**
     * @param $formDefinition
     */
    protected function updateStateDeletedState(&$formDefinition) {
        $formDefinition['renderingOptions']['fieldState'] = array_map(function ($field) {
            $field['renderingOptions']['deleted'] = isset($field['active']) ? 0 : 1;
            unset($field['active']);
            return $field;
        }, $formDefinition['renderingOptions']['fieldState'] );
    }

    /**
     * @param $field
     * @param $formDefinition
     */
    protected function updateNewFieldIdentifierAndAddToState(&$field, &$formDefinition) {
        //  Opdatere identifier med $fieldTypesNextIdentifier
        $field['identifier'] = $this->getNextIdentifier($field['type'], $field['identifier']);
        //  Tilføje ny til fieldState
        $formDefinition['renderingOptions']['fieldState'][$field['identifier']] = $field;
    }

    protected function updateStateListViewState(&$formDefinition, $fieldCount) {
        if(!isset($formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'])) {
            if($fieldCount <= $this->enableListViewUntilCount) {
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'] = 1;
            } else {
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'] = 0;
            }
        }

    }

    /**
     * @param $fieldState
     */
    protected function makeNextIdentifiersMap($fieldState) {
        foreach ($fieldState as $identifier => &$field) {
            list($identifierText, $identifierNumber) = explode('-', $field['identifier']);

            if(!isset($this->fieldTypesNextIdentifier[$field['type']])) {
                $this->fieldTypesNextIdentifier[$field['type']] = ['text' => $identifierText, 'number' => $identifierNumber];
            } else {
                $this->fieldTypesNextIdentifier[$field['type']] = ['text' => $identifierText, 'number' => max($this->fieldTypesNextIdentifier[$field['type']]['number'], $identifierNumber)];
            }
        }
        array_walk($this->fieldTypesNextIdentifier, function (&$val) { $val['number']++; });
    }

    /**
     * @param $type
     * @return int
     */
    protected function getNextIdentifier($type, $identifier) {
        if(isset($this->fieldTypesNextIdentifier[$type])) {
            $identifier = $this->fieldTypesNextIdentifier[$type]['text'] . '-' . $this->fieldTypesNextIdentifier[$type]['number'];
            $this->fieldTypesNextIdentifier[$type]['number']++;
        }
        return $identifier;
    }
}