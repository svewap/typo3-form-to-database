<?php /** @noinspection PhpInternalEntityUsedInspection */

/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Utility;

use TYPO3\CMS\Form\Domain\Model\FormDefinition;

/**
 * Class FormDefinitionUtility
 *
 * @package Lavitto\FormToDatabase\Utility
 */
class FormDefinitionUtility
{

    /**
     * @var array
     */
    protected $fieldTypesNextIdentifier = [];

    /**
     * @var int $enableListViewUntilCount
     */
    protected $enableListViewUntilCount = 4;

    /**
     * @var array
     */
    protected $fieldAttributeFilterKeys = ['identifier', 'label', 'type'];

    /**
     * @param array|FormDefinition $formDefinition
     * @param bool $enableAllInListView
     */
    public function addFieldStateIfDoesNotExist(&$formDefinition, $enableAllInListView = false): void
    {
        $fieldStateExists = is_object($formDefinition) ? isset($formDefinition->getRenderingOptions()['fieldState']) : isset($formDefinition['renderingOptions']['fieldState']);
        //If no state exists - create state from current fields
        if (!$fieldStateExists) {
            $fieldState = $this->getFieldsFromFormDefinition($formDefinition);
            $fieldCount = 0;
            //Mark all fields in state as not deleted
            $fieldState = array_map(function ($field) use (&$fieldCount, $enableAllInListView) {
                $fieldCount++;
                $field['renderingOptions']['deleted'] = 0;
                $field['renderingOptions']['listView'] = ($enableAllInListView || $fieldCount <= $this->enableListViewUntilCount) ? 1 : 0;
                return $field;
            }, $fieldState);
            if (is_object($formDefinition)) {
                $formDefinition->setRenderingOption('fieldState', $fieldState);
            } else {
                $formDefinition['renderingOptions']['fieldState'] = $fieldState;
            }
        }
    }

    /**
     * @param FormDefinition|array $formDefinition
     * @return array
     */
    protected function getFieldsFromFormDefinition($formDefinition): array
    {
        $fields = [];
        $renderables = is_object($formDefinition) ? $formDefinition->getRenderablesRecursively() : $formDefinition['renderables'];
        if (is_object($formDefinition)) {
            foreach ($renderables as $element) {
                if (get_class($element) === 'TYPO3\CMS\Form\Domain\Model\FormElements\Page') {
                    continue;
                }
                $identifier = $element->getIdentifier();
                $fields[$identifier] = [
                    'identifier' => $identifier,
                    'label' => $element->getLabel(),
                    'type' => $element->getType()
                ];
            }
        } else {
            foreach ($renderables as $steps) {
                if (!$steps['renderables']) {
                    continue;
                }
                foreach ($steps['renderables'] as $field) {
                    $fields[$field['identifier']] = $this->filterFieldAttributes($field);
                }
            }
        }
        return $fields;
    }

    /**
     * @param $field
     * @return array
     */
    protected function filterFieldAttributes($field): array
    {
        return array_intersect_key($field, array_flip($this->fieldAttributeFilterKeys));
    }

    /**
     * Make sure that field identifiers are unique (identifiers of deleted fields are not reused)
     * Field state is saved to keep track of old fields
     *
     * @param $formDefinition
     * @return mixed
     */
    public function updateFormDefinition($formDefinition)
    {
        $this->addFieldStateIfDoesNotExist($formDefinition);
        //Make map of next identifier for each field type
        $this->makeNextIdentifiersMap($formDefinition['renderingOptions']['fieldState']);

        $fieldCount = 0;
        foreach ($formDefinition['renderables'] as &$steps) {
            if (!$steps['renderables']) {
                continue;
            }
            foreach ($steps['renderables'] as &$field) {
                $fieldCount++;
                //New field - field identifier does not exist in state OR New field - but identifier exists(deleted field) in state
                if (
                    !isset($formDefinition['renderingOptions']['fieldState'][$field['identifier']])
                    ||
                    $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['deleted'] === 1
                ) {
                    $this->updateNewFieldIdentifier($field);
                    $this->addFieldToState($formDefinition, $field);
                    $this->updateListViewState($formDefinition, $field, $fieldCount);
                    //Existing field - update state
                } else {
                    $formDefinition['renderingOptions']['fieldState'][$field['identifier']] = array_merge($formDefinition['renderingOptions']['fieldState'][$field['identifier']],
                        $this->filterFieldAttributes($field));
                }
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['active'] = 1;
            }
        }
        unset($steps, $field);

        //Mark all fields that are found in renderables as deleted in state
        $this->updateStateDeletedState($formDefinition);
        return $formDefinition;
    }

    /**
     * @param $fieldState
     */
    protected function makeNextIdentifiersMap($fieldState): void
    {
        foreach ($fieldState as $identifier => &$field) {
            [$identifierText, $identifierNumber] = explode('-', $field['identifier']);

            if (!isset($this->fieldTypesNextIdentifier[$field['type']])) {
                $this->fieldTypesNextIdentifier[$field['type']] = [
                    'text' => $identifierText,
                    'number' => $identifierNumber
                ];
            } else {
                $this->fieldTypesNextIdentifier[$field['type']] = [
                    'text' => $identifierText,
                    'number' => max($this->fieldTypesNextIdentifier[$field['type']]['number'], $identifierNumber)
                ];
            }
        }
        unset($field);
        array_walk($this->fieldTypesNextIdentifier, static function (&$val) {
            $val['number']++;
        });
    }

    /**
     * @param $field
     */
    protected function updateNewFieldIdentifier(&$field): void
    {
        //  Opdatere identifier med $fieldTypesNextIdentifier
        $field['identifier'] = $this->getNextIdentifier($field['type'], $field['identifier']);
    }

    /**
     * @param $type
     * @param $identifier
     * @return string
     */
    protected function getNextIdentifier($type, $identifier): string
    {
        if (isset($this->fieldTypesNextIdentifier[$type])) {
            $identifier = $this->fieldTypesNextIdentifier[$type]['text'] . '-' . $this->fieldTypesNextIdentifier[$type]['number'];
            $this->fieldTypesNextIdentifier[$type]['number']++;
        }
        return $identifier;
    }

    /**
     * @param $field
     * @param $formDefinition
     */
    protected function addFieldToState(&$formDefinition, $field): void
    {
        //  Tilføje ny til fieldState
        $formDefinition['renderingOptions']['fieldState'][$field['identifier']] = $this->filterFieldAttributes($field);
    }

    /**
     * @param $formDefinition
     * @param $field
     * @param $fieldCount
     */
    protected function updateListViewState(&$formDefinition, $field, $fieldCount): void
    {
        if (!isset($formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'])) {
            if ($fieldCount <= $this->enableListViewUntilCount) {
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'] = 1;
            } else {
                $formDefinition['renderingOptions']['fieldState'][$field['identifier']]['renderingOptions']['listView'] = 0;
            }
        }
    }

    /**
     * @param $formDefinition
     */
    protected function updateStateDeletedState(&$formDefinition): void
    {
        $formDefinition['renderingOptions']['fieldState'] = array_map(static function ($field) {
            $field['renderingOptions']['deleted'] = isset($field['active']) ? 0 : 1;
            unset($field['active']);
            return $field;
        }, $formDefinition['renderingOptions']['fieldState']);
    }
}
