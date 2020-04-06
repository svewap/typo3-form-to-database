<?php /** @noinspection PhpUnusedParameterInspection */
/** @noinspection PhpInternalEntityUsedInspection */

/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Hooks;

use Lavitto\FormToDatabase\Domain\Model\FormResult;
use Lavitto\FormToDatabase\Domain\Repository\FormResultRepository;
use Lavitto\FormToDatabase\Utility\FormDefinitionUtility;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\Generic\QueryResult;
use TYPO3\CMS\Form\Mvc\Configuration\YamlSource;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManager;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

/**
 * Class FormHooks
 *
 * @package Lavitto\FormToDatabase\Hooks
 */
class FormHooks
{

    /**
     * @var array
     */
    protected $fieldTypesNextIdentifier = [];

    /**
     * @var FormPersistenceManager
     */
    protected $formPersistenceManager;

    /**
     * @var int $enableListViewUntilCount
     */
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
    protected function initializeFormPersistenceManager(): void
    {
        /** @var FormPersistenceManagerInterface $formPersistenceManager */
        $this->formPersistenceManager = GeneralUtility::makeInstance(FormPersistenceManager::class);
        $this->formPersistenceManager->initializeObject();
        $this->formPersistenceManager->injectResourceFactory(ResourceFactory::getInstance());
        $this->formPersistenceManager->injectYamlSource(GeneralUtility::makeInstance(YamlSource::class));
    }

    /**
     * @param $formPersistenceIdentifier
     * @throws IllegalObjectTypeException
     * @throws UnknownObjectException
     * @noinspection PhpParamsInspection
     * @noinspection PhpUndefinedMethodInspection
     */
    public function beforeFormDelete($formPersistenceIdentifier): void
    {
        $this->initializeFormResultRepository();
        $resourceFactory = ResourceFactory::getInstance();
        $this->initializeFormPersistenceManager();
        $yaml = $this->formPersistenceManager->load($formPersistenceIdentifier);

        /** @var File $file */
        $file = $resourceFactory->getFileObjectFromCombinedIdentifier($formPersistenceIdentifier);

        // New unique filename
        $newFilename = "{$yaml['identifier']}.form.yaml.deleted";
        if ($file !== null) {
            $newCombinedIdentifier = $file->copyTo($file->getParentFolder(), $newFilename)->getCombinedIdentifier();
            /** @var QueryResult $results */
            $results = $this->formResultRepository->findByFormIdentifier($yaml['identifier']);
            /** @var FormResult $result */
            foreach ($results as $result) {
                $result->setFormPersistenceIdentifier($newCombinedIdentifier);
                $this->formResultRepository->update($result);
            }
        }
    }

    /**
     * @param $formPersistenceIdentifier
     * @param $form
     * @return mixed
     */
    public function beforeFormCreate($formPersistenceIdentifier, $form)
    {
        $form['identifier'] .= '-' . uniqid('', true);
        return $form;
    }

    /**
     * @param $formPersistenceIdentifier
     * @param $formToDuplicate
     * @return mixed
     */
    public function beforeFormDuplicate($formPersistenceIdentifier, $formToDuplicate)
    {
        $formToDuplicate['identifier'] = preg_replace('/(.*)-([a-z0-9]{13})/', '$1',
                $formToDuplicate['identifier']) . '-' . uniqid('', true);
        return $formToDuplicate;
    }

    /**
     * Keep track of field identifiers of deleted and new fields, so that identifiers are not reused
     *
     * @param $formPersistenceIdentifier
     * @param $formDefinition
     * @return mixed
     */
    public function beforeFormSave($formPersistenceIdentifier, $formDefinition)
    {
        /** @var FormDefinitionUtility $formDefinitionUtility */
        $formDefinitionUtility = GeneralUtility::makeInstance(FormDefinitionUtility::class);
        return $formDefinitionUtility->updateFormDefinition($formDefinition);
    }
}
