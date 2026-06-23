<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Domain\Repository;

use Kitodo\Dlf\Domain\Model\LendingSeat;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repository for LendingSeat domain objects.
 *
 * Provides the finder and count methods required by LendingManagerService.
 * Uses raw Doctrine DBAL for the countActiveByDocument and findExpiredByDocument
 * queries because Extbase's QueryBuilder does not support arithmetic comparisons
 * on integer columns against PHP timestamps as cleanly as raw SQL.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 *
 * @extends AbstractRepository<LendingSeat>
 */
class LendingSeatRepository extends AbstractRepository
{
    /**
     * Finds a seat record for a specific document and user combination.
     *
     * Returns null if the user does not currently hold a seat, regardless
     * of whether the seat has expired (expiry check is the caller's concern).
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID
     *
     * @return LendingSeat|null
     */
    public function findByDocumentAndUser(int $documentUid, int $feUserUid): ?LendingSeat
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        $query->matching(
            $query->logicalAnd(
                $query->equals('document', $documentUid),
                $query->equals('feUser', $feUserUid)
            )
        );

        /** @var LendingSeat|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Counts the number of non-expired seats currently active for a document.
     *
     * Uses raw DBAL so we can compare expiresAt > NOW() as a single query
     * rather than loading all rows into PHP objects and filtering there.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     *
     * @return int Number of active (non-expired) seats
     */
    public function countActiveByDocument(int $documentUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_dlf_lending_seats');

        return (int) $queryBuilder
            ->count('uid')
            ->from('tx_dlf_lending_seats')
            ->where(
                $queryBuilder->expr()->eq(
                    'document',
                    $queryBuilder->createNamedParameter($documentUid, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->gt(
                    'expires_at',
                    $queryBuilder->createNamedParameter(time(), \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Returns all expired seat records for a document.
     *
     * Called by LendingManagerService::purgeExpiredSeats() to clean up stale
     * rows before checking occupancy. Returns Extbase model objects so they
     * can be passed directly to AbstractRepository::remove().
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $now Current Unix timestamp (injected for testability)
     *
     * @return LendingSeat[]
     */
    public function findExpiredByDocument(int $documentUid, int $now): array
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        $query->matching(
            $query->logicalAnd(
                $query->equals('document', $documentUid),
                $query->lessThanOrEqual('expiresAt', $now)
            )
        );

        /** @var LendingSeat[] $results */
        $results = $query->execute()->toArray();
        return $results;
    }
}
