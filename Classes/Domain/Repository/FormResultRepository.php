<?php
/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Domain\Repository;

use DateInterval;
use DateTime;
use Exception;
use Lavitto\FormToDatabase\Utility\FormValueUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\QueryGenerator;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
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
     * @throws InvalidQueryException
     */
    protected function createQueryByFormPersistenceIdentifier(string $formPersistenceIdentifier): QueryInterface
    {
        $query = $this->createQuery();
        $webMounts = $this->getWebMounts();
        $siteIdentifiers = $this->getSiteIdentifiersFromRootPids($webMounts);
        $pluginUids = $this->getPluginUids($webMounts);

        $query->matching(
            $query->logicalAnd([
                $query->equals('formPersistenceIdentifier', $formPersistenceIdentifier),
                $query->logicalOr([
                    $query->in('formPluginUid', $pluginUids),
                    $query->in('siteIdentifier', $siteIdentifiers),
                    //To include all records created before pid and siteIdentifier was taken into account
                    $query->logicalAnd([
                        $query->equals('siteIdentifier', ''),
                        $query->equals('pid', 0)
                    ])
                ])
            ])
        );
        return $query;
    }

    /**
     * Get webmounts of BE User
     *
     * @return array
     */
    protected function getWebMounts () {
        $webMounts = [];
        if ($GLOBALS['BE_USER']->groupData['webmounts']) {
            $webMounts = GeneralUtility::trimExplode(',', $GLOBALS['BE_USER']->groupData['webmounts'], 1);
        }
        return $webMounts;
    }

    /**
     * @param $webMounts
     * @return array
     */
    protected function getPluginUids($webMounts) {
        $pids = $this->getTreePids($webMounts);
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('pid', $pids),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('form_formframework', \PDO::PARAM_STR))
            )
            ->execute()->fetchAll();
        return array_column($result, 'uid');
    }

    /**
     * Get all pids which user can access
     *
     * @param array $webMounts
     * @return array
     */
    function getTreePids($webMounts = 0){
        $childPidsArray = [];
        if($webMounts) {
            $depth = 99;
            $childPidsArray = [];
            /** @var QueryGenerator $queryGenerator */
            $queryGenerator = GeneralUtility::makeInstance( QueryGenerator::class );
            foreach ($webMounts as $webMount) {
                $childPids = $queryGenerator->getTreeList($webMount, $depth, 0, 1); //Will be a string like 1,2,3
                $childPidsArray = array_merge($childPidsArray, explode(',', $childPids ));
            }
        }
        return array_unique($childPidsArray);
    }

    /**
     * Get SiteIdentifiers from Root Pids
     *
     * @return array
     */
    protected function getSiteIdentifiersFromRootPids($webMounts) {
        $siteIdentifiers = [];
        if ($webMounts) {
            //find site identifiers from mountpoints
            /** @var SiteFinder $siteMatcher */
            $siteMatcher = GeneralUtility::makeInstance(SiteFinder::class);
            foreach ($webMounts as $webMount) {
                try {
                    $site = $siteMatcher->getSiteByRootPageId((int)$webMount);
                    $siteIdentifiers[] = $site->getIdentifier();
                } catch (SiteNotFoundException $exception) {}
            }
        }
        return $siteIdentifiers;
    }

    /**
     * Returns all form results were older than "maxAge" (days)
     *
     * @param int $maxAge
     * @return QueryResultInterface
     * @throws InvalidQueryException
     * @throws Exception
     */
    public function findByMaxAge(int $maxAge): QueryResultInterface
    {
        $dateInterval = DateInterval::createFromDateString($maxAge . ' days');
        $maxDate = new DateTime('now',
            FormValueUtility::getValidTimezone((string)$GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone']));
        $maxDate->sub($dateInterval);
        $query = $this->createQuery();
        $query->matching($query->lessThan('tstamp', $maxDate));
        return $query->execute();
    }
}