<?php /** @noinspection PhpInternalEntityUsedInspection */

/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Utility;

use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Model\FormElements\Page;

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
    protected array $fieldTypesNextIdentifier = [];

    /**
     * @var int $enableListViewUntilCount
     */
    protected int $enableListViewUntilCount = 4;

    const fieldAttributeFilterKeys = ['identifier', 'label', 'type'];

    const nonInputRenderables = ['Page', 'SummaryPage', 'GridRow', 'Fieldset', 'Section', 'Honeypot', 'StaticText', 'ContentElement'];


    /**
     * @param array|FormDefinition $formDefinition
     * @param bool $enableAllInListView
     * @param bool $force
     */
    public function addFieldStateIfDoesNotExist(
        &$formDefinition,
        bool $enableAllInListView = false,
        bool $force = false
    ): void {
        $fieldState = $formDefinition['renderingOptions']['fieldState'] ?? [];

        // If no state exists - create state from current fields
        if (empty($fieldState) || $force === true) {
            $newFieldState = $this->addFieldsToStateFromFormDefintion($formDefinition, $fieldState);
            $fieldCount = 0;
            //Mark all fields in state as not deleted
            $newFieldState = array_map(function ($field) use (&$fieldCount, $enableAllInListView) {
                $fieldCount++;
                $field['renderingOptions']['deleted'] = 0;
                $field['renderingOptions']['listView'] = ($enableAllInListView || $fieldCount <= $this->enableListViewUntilCount) ? 1 : 0;
                return $field;
            }, $newFieldState);
            $formDefinition['renderingOptions']['fieldState'] = $newFieldState;
        }
    }

    /**
     * OVERWRITES FIELDSTATE
     * @param array $formDefinition
     * @param array $fieldState
     * @return array
     */
    protected function addFieldsToStateFromFormDefintion(array $formDefinition, array $fieldState = []): array
    {
        $renderables = $formDefinition['renderables'] ?? [];
        foreach ($renderables as $renderable) {
            if (!empty($renderable['renderables'])) {
                $fieldState = $this->addFieldsToStateFromFormDefintion($renderable, $fieldState);
            } elseif (!empty($renderable['identifier'])) {
                if(in_array($renderable['type'], self::nonInputRenderables)) continue;
                ArrayUtility::mergeRecursiveWithOverrule($fieldState[$renderable['identifier']], $this->filterFieldAttributes($renderable));
            }
        }
        return $fieldState;
    }

    /**
     * @param $field
     * @return array
     */
    protected function filterFieldAttributes($field): array
    {
        return array_intersect_key($field, array_flip(self::fieldAttributeFilterKeys));
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
        $this->addFieldStateIfDoesNotExist($formDefinition, false, true);
        //Make map of next identifier for each field type
        $this->makeNextIdentifiersMap($formDefinition['renderingOptions']['fieldState']);

        $fieldCount = 0;
        foreach ($formDefinition['renderables'] as &$steps) {
            if (empty($steps['renderables'])) {
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
            $identifierParts = explode('-', $field['identifier']);
            $identifierText = $identifierParts[0];
            $identifierNumber = $identifierParts[1] ?? '0';

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
