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
use PDO;
use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
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
     * The PageTreeRepository
     *
     * @var PageTreeRepository
     */
    protected $pageTreeRepository;

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
     * Injects the PageTreeRepository
     *
     * @param PageTreeRepository $pageTreeRepository
     */
    public function injectPageTreeRepository(PageTreeRepository $pageTreeRepository): void
    {
        $this->pageTreeRepository = $pageTreeRepository;
    }

    /**
     * Gets all results by form definition
     *
     * @param string $formPersistenceIdentifier
     * @return QueryResultInterface
     * @throws InvalidQueryException
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
     * @throws InvalidQueryException
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
        if (empty($webMounts) === false) {
            $siteIdentifiers = $this->getSiteIdentifiersFromRootPids($webMounts);
            $pluginUids = $this->getPluginUids($webMounts);
            $query->matching(
                $query->logicalAnd([
                    $query->equals('formPersistenceIdentifier', $formPersistenceIdentifier),
                    $query->logicalOr([
                        $query->in('formPluginUid', $pluginUids),
                        $query->in('siteIdentifier', $siteIdentifiers),
                        // To include all records created before pid and siteIdentifier was taken into account
                        $query->logicalAnd([
                            $query->equals('siteIdentifier', ''),
                            $query->equals('pid', 0)
                        ])
                    ])
                ])
            );
        }
        return $query;
    }

    /**
     * Get webMounts of BE User
     *
     * @return array
     */
    protected function getWebMounts(): array
    {
        return $GLOBALS['BE_USER']->returnWebmounts();
    }

    /**
     * Gets the plugin uids
     *
     * @param array $webMounts
     * @return array
     */
    protected function getPluginUids(array $webMounts): array
    {
        $pids = $this->getTreePids($webMounts);
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('pid', $pids),
                $queryBuilder->expr()->eq('CType',
                    $queryBuilder->createNamedParameter('form_formframework', PDO::PARAM_STR))
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
    protected function getTreePids(array $webMounts): array
    {
        $pidsArray = [];
        if ($webMounts !== null) {
            foreach ($webMounts as $webMount) {
                $pageTree = $this->pageTreeRepository->getTree((int)$webMount);
                $this->addTreeToPidsArray($pidsArray, $pageTree);
            }
        }
        return array_unique($pidsArray);
    }

    /**
     * Adds the uids of pages from pagetree to an array
     *
     * @param $pidsArray
     * @param array $pageTree
     */
    protected function addTreeToPidsArray(&$pidsArray, array $pageTree): void
    {
        $pidsArray[] = $pageTree['uid'];
        if (empty($pageTree['_children']) === false) {
            foreach ($pageTree['_children'] as $children) {
                $this->addTreeToPidsArray($pidsArray, $children);
            }
        }
    }

    /**
     * Get SiteIdentifiers from Root Pids
     *
     * @param array $webMounts
     * @return array
     */
    protected function getSiteIdentifiersFromRootPids(array $webMounts): array
    {
        $siteIdentifiers = [];
        if ($webMounts) {
            // find site identifiers from mountpoints
            /** @var SiteFinder $siteMatcher */
            $siteMatcher = GeneralUtility::makeInstance(SiteFinder::class);
            foreach ($webMounts as $webMount) {
                if ((int)$webMount === 0) {
                    /** @var Site $site */
                    foreach ($siteMatcher->getAllSites() as $site) {
                        $siteIdentifiers[] = $site->getIdentifier();
                    }
                } else {
                    try {
                        $site = $siteMatcher->getSiteByPageId((int)$webMount);
                        $siteIdentifiers[] = $site->getIdentifier();
                    } catch (SiteNotFoundException $exception) {
                    }
                }
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
