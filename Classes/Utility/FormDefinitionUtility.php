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

    const fieldAttributeFilterKeys = ['identifier', 'label', 'type'];

    const nonInputRenderables = ['Page', 'SummaryPage', 'GridRow', 'Fieldset', 'Section', 'Honeypot', 'StaticText', 'ContentElement'];


    /**
     * @param array|FormDefinition $formDefinition
     * @param bool $enableAllInListView
     * @param bool $force
     */
    public static function addFieldStateIfDoesNotExist(
        &$formDefinition,
        bool $enableAllInListView = false,
        bool $force = false
    ): void {
        $fieldState = $formDefinition['renderingOptions']['fieldState'] ?? [];

        // If no state exists - create state from current fields
        if (empty($fieldState) || $force === true) {
            $newFieldState = self::addFieldsToStateFromFormDefinition($formDefinition, $fieldState);
            $fieldCount = 0;
            //Mark all fields in state as not deleted
            $newFieldState = array_map(function ($field) use (&$fieldCount, $enableAllInListView) {
                $fieldCount++;
                if(!isset($field['renderingOptions']['deleted'])) {
                    $field['renderingOptions']['deleted'] = 0;
                }
//                $field['renderingOptions']['deleted'] = 0;
//                $field['renderingOptions']['listView'] = ($enableAllInListView || $fieldCount <= $this->enableListViewUntilCount) ? 1 : 0;
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
    protected static function addFieldsToStateFromFormDefinition(array $formDefinition, array $fieldState = []): array
    {
        $renderables = $formDefinition['renderables'] ?? [];
        foreach ($renderables as $renderable) {
            if (!empty($renderable['renderables'])) {
                $fieldState = self::addFieldsToStateFromFormDefinition($renderable, $fieldState);
            } elseif (!empty($renderable['identifier'])) {
                if(in_array($renderable['type'], self::nonInputRenderables)) continue;
                self::addFieldToState($fieldState[$renderable['identifier']], $renderable);
            }
        }
        return $fieldState;
    }

    /**
     * @param $field
     * @param $fieldState
     */
    public static function addFieldToState(&$fieldState, $field): void
    {
        $newFieldState = array_intersect_key($field, array_flip(self::fieldAttributeFilterKeys));
        ArrayUtility::mergeRecursiveWithOverrule($fieldState, $newFieldState);
    }
}
