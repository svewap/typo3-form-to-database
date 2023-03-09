<?php /** @noinspection PhpInternalEntityUsedInspection */

/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Utility;

use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManager;

/**
 * Class FormDefinitionUtility
 *
 * @package Lavitto\FormToDatabase\Utility
 */
class UniqueFieldHandler
{

    /**
     * @var int $enableListViewUntilCount
     */
    protected int $enableListViewUntilCount = 4;

    /**
     * @var array
     */
    protected array $fieldTypesNextIdentifier = [];

    /**
     * @var FormPersistenceManager
     */
    protected FormPersistenceManager $formPersistenceManager;

    /**
     * @param FormPersistenceManager $formPersistenceManager
     */
    public function __construct(FormPersistenceManager $formPersistenceManager)
    {
        $this->formPersistenceManager = $formPersistenceManager;
    }

    /**
     * Makes sure that field identifiers are unique (identifiers of deleted fields are not reused)
     * Field state is saved to keep track of old fields
     *
     * @param $formDefinition
     * @return mixed
     */
    public function updateNewFields($formDefinition)
    {
        $fieldCount = 0;

        $recursiveRenderableUpdate = function (array &$renderables) use (&$fieldCount, &$formDefinition, &$recursiveRenderableUpdate) {
            if (!empty($renderable['renderables'])) {
                foreach ($renderables as &$renderable) {
                    if($renderable['renderables']) {
                        $recursiveRenderableUpdate($renderable['renderables']);
                    } else {
                        // This check assumes that it is possible, that composite element does not always have renderables. If this is not the case, this check could be removed
                        if(!FormDefinitionUtility::isCompositeElement($renderable)) {
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
                            FormDefinitionUtility::addFieldToState($formDefinition['renderingOptions']['fieldState'], $renderable);
                        }
                    }
                }
            }
        };

        FormDefinitionUtility::addFieldStateIfDoesNotExist($formDefinition, false, true);
        //Make map of next identifier for each field type
        $this->makeNextIdentifiersMap($formDefinition['renderingOptions']['fieldState']);

        $recursiveRenderableUpdate($formDefinition['renderables']);

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
