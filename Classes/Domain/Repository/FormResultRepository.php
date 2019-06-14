<?php
/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Class FormResultRepository
 *
 * @package Lavitto\FormToDatabase\Domain\Repository
 */
class FormResultRepository extends Repository
{

    /**
     * Sort by tstamp desc
     *
     * @var array
     */
    protected $defaultOrderings = [
        'tstamp' => QueryInterface::ORDER_DESCENDING
    ];

    /**
     * Ignore storage pid
     */
    public function initializeObject(): void
    {
        /** @var Typo3QuerySettings $defaultQuerySettings */
        $defaultQuerySettings = $this->objectManager->get(Typo3QuerySettings::class);
        $defaultQuerySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($defaultQuerySettings);
    }

    /**
     * Gets all results by form definition
     *
     * @param string $formPersistenceIdentifier
     * @return QueryResultInterface
     */
    public function findByFormPersistenceIdentifier(string $formPersistenceIdentifier): QueryResultInterface
    {
        return $this->createQueryByFormPersistenceIdentifier($formPersistenceIdentifier)->execute();
    }

    /**
     * Counts all results by form definition
     *
     * @param string $formPersistenceIdentifier
     * @return int
     */
    public function countByFormPersistenceIdentifier(string $formPersistenceIdentifier): int
    {
        return $this->createQueryByFormPersistenceIdentifier($formPersistenceIdentifier)->count();
    }

    /**
     * Creates a query with by formPersistenceIdentifier
     *
     * @param string $formPersistenceIdentifier
     * @return QueryInterface
     */
    protected function createQueryByFormPersistenceIdentifier(string $formPersistenceIdentifier): QueryInterface
    {
        $query = $this->createQuery();
        $query->matching($query->equals('formPersistenceIdentifier', $formPersistenceIdentifier));
        return $query;
    }
}
