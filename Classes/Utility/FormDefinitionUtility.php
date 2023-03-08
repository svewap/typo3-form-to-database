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
            $newFieldState = $this->addFieldsToStateFromFormDefinition($formDefinition, $fieldState);
            $fieldCount = 0;
            //Mark all fields in state as not deleted
            $newFieldState = array_map(function ($field) use (&$fieldCount, $enableAllInListView) {
                $fieldCount++;
                $field['renderingOptions']['deleted'] = 0;
                $field['renderingOptions']['listView'] = ($enableAllInListView || $fieldCount <= $this->enableListViewUntilCount) ? 1 : 0;
                return $field;
            }, $newFieldState);
            // Clean up fieldState - remove if non-input field or incomplete
            $newFieldState = array_filter($newFieldState, function($field) {
                return
                    !in_array($field['type'], self::nonInputRenderables)
                    &&
                    count(
                        array_intersect_key(array_flip(self::fieldAttributeFilterKeys,), $field)
                    ) === count(self::fieldAttributeFilterKeys);
            });

            $formDefinition['renderingOptions']['fieldState'] = $newFieldState;
        }
    }

    /**
     * @param array $formDefinition
     * @param array $fieldState
     * @return array
     */
    protected function addFieldsToStateFromFormDefinition(array $formDefinition, array $fieldState = []): array
    {
        $renderables = $formDefinition['renderables'] ?? [];
        foreach ($renderables as $renderable) {
            if (!empty($renderable['renderables'])) {
                $fieldState = $this->addFieldsToStateFromFormDefinition($renderable, $fieldState);
            } elseif (!empty($renderable['identifier'])) {
                if(in_array($renderable['type'], self::nonInputRenderables)) continue;
                $this->addFieldToState($fieldState[$renderable['identifier']], $renderable);
            }
        }
        return $fieldState;
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
        $fieldCount = 0;
        // Identify new field
        $recursiveRenderableUpdate = function (array &$renderable) use (&$fieldCount, &$formDefinition, &$recursiveRenderableUpdate) {
            if (!empty($renderable['renderables'])) {
                foreach ($renderable['renderables'] as &$renderable2) {
                    $recursiveRenderableUpdate($renderable2);
                }
            } elseif (!empty($renderable['identifier'])) {
                if(in_array($renderable['type'], self::nonInputRenderables)) {
                    $formDefinition['renderingOptions']['fieldState'][$renderable['identifier']]['active'] = 1;
                    $fieldCount++;
                    // Identifier does not exist in state OR
                    // Identifier exists as deleted in state
                    if (
                        !isset($formDefinition['renderingOptions']['fieldState'][$renderable['identifier']])
                        ||
                        $formDefinition['renderingOptions']['fieldState'][$renderable['identifier']]['renderingOptions']['deleted'] === 1
                    ) {
                        $this->updateNewFieldWithNextIdentifier($renderable);
                        $this->updateListViewState($formDefinition, $renderable, $fieldCount);
                        //Existing field - update state
                    }
                    $this->addFieldToState($formDefinition['renderingOptions']['fieldState'], $renderable);
                }
            }
        };

        $this->addFieldStateIfDoesNotExist($formDefinition, false, true);
        //Make map of next identifier for each field type
        $this->makeNextIdentifiersMap($formDefinition['renderingOptions']['fieldState']);

        $recursiveRenderableUpdate($formDefinition);

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
    protected function updateNewFieldWithNextIdentifier(&$field): void
    {
        if (isset($this->fieldTypesNextIdentifier[$field['type']])) {
            $this->fieldTypesNextIdentifier[$field['type']]['number']++;
            $field['identifier'] = $this->fieldTypesNextIdentifier[$field['type']]['text'] . '-' . $this->fieldTypesNextIdentifier[$field['type']]['number'];
        }
    }

    /**
     * @param $field
     * @param $fieldState
     */
    protected function addFieldToState(&$fieldState, $field): void
    {
        $newFieldState = array_intersect_key($field, array_flip(self::fieldAttributeFilterKeys));
        ArrayUtility::mergeRecursiveWithOverrule($fieldState, $newFieldState);
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
