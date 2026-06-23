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

namespace Kitodo\Dlf\Service;

use Kitodo\Dlf\Domain\Model\LendingSeat;
use Kitodo\Dlf\Domain\Repository\LendingSeatRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Manages concurrent seat limits and waitlists for lending-restricted documents.
 *
 * This service is called exclusively by PolicyDecisionService when the
 * AccessPolicy for a document contains a concurrentLimit > 0. It is never
 * called directly from controllers; the PDP is the single entry point for
 * all access decisions.
 *
 * A "seat" represents one active reading session for a document. Once all
 * seats are occupied, subsequent requests join a FIFO waitlist. Seats are
 * released either explicitly (when the user navigates away and the browser
 * calls the release endpoint) or automatically when their TTL expires.
 *
 * Expired seats are cleaned up lazily on every acquireSeat() call for the
 * same document, keeping the logic simple without a background scheduler.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class LendingManagerService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @access protected
     * @var LendingSeatRepository
     */
    protected LendingSeatRepository $lendingSeatRepository;

    /**
     * @access protected
     * @var PersistenceManagerInterface
     */
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Seat TTL in seconds. Matches the JWT TTL so a seat is held for exactly
     * one token lifetime. When the client refreshes its token, it implicitly
     * extends its seat by calling PolicyDecisionService::evaluate() again.
     *
     * @access private
     * @var int
     */
    private int $seatTtlSeconds;

    /**
     * @access public
     *
     * @param int $seatTtlSeconds Seat lifetime in seconds (default: 900 = 15 min)
     */
    public function __construct(int $seatTtlSeconds = 900)
    {
        $this->seatTtlSeconds = $seatTtlSeconds;
    }

    /**
     * @access public
     *
     * @param LendingSeatRepository $lendingSeatRepository
     *
     * @return void
     */
    public function injectLendingSeatRepository(LendingSeatRepository $lendingSeatRepository): void
    {
        $this->lendingSeatRepository = $lendingSeatRepository;
    }

    /**
     * @access public
     *
     * @param PersistenceManagerInterface $persistenceManager
     *
     * @return void
     */
    public function injectPersistenceManager(PersistenceManagerInterface $persistenceManager): void
    {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * Attempts to acquire a seat for a user on a document.
     *
     * If the user already holds a seat for this document (e.g. they reloaded
     * the page within the TTL window) the existing seat's expiry is renewed
     * and it is returned — we do not double-count the same user.
     *
     * If a free seat is available a new LendingSeat record is created,
     * persisted immediately, and returned.
     *
     * If all seats are occupied null is returned; the caller (PDP) will
     * return an AccessVerdict with reason='seat_limit'.
     *
     * Expired seats for the requested document are purged before the
     * occupancy check so stale rows never block legitimate access.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID; must be > 0 (anonymous users cannot hold seats)
     * @param int $concurrentLimit The maximum number of simultaneous readers
     *
     * @return LendingSeat|null The acquired or renewed seat, or null if fully occupied
     */
    public function acquireSeat(int $documentUid, int $feUserUid, int $concurrentLimit): ?LendingSeat
    {
        if ($feUserUid <= 0) {
            $this->logger->warning(
                'LendingManagerService: acquireSeat called with anonymous user (uid=0) for document ' . $documentUid
            );
            return null;
        }

        // Clean up expired seats first so they do not count toward the limit.
        $this->purgeExpiredSeats($documentUid);

        // If the user already holds a seat, renew its expiry and return it.
        $existingSeat = $this->lendingSeatRepository->findByDocumentAndUser($documentUid, $feUserUid);
        if ($existingSeat !== null) {
            $existingSeat->setExpiresAt(time() + $this->seatTtlSeconds);
            $this->lendingSeatRepository->update($existingSeat);
            $this->persistenceManager->persistAll();
            $this->logger->debug(
                'LendingManagerService: renewed seat for user ' . $feUserUid . ' on document ' . $documentUid
            );
            return $existingSeat;
        }

        // Count currently active seats.
        $activeCount = $this->lendingSeatRepository->countActiveByDocument($documentUid);
        if ($activeCount >= $concurrentLimit) {
            $this->logger->info(
                'LendingManagerService: all ' . $concurrentLimit . ' seats occupied for document ' . $documentUid
            );
            return null;
        }

        // Create and persist the new seat.
        $seat = GeneralUtility::makeInstance(LendingSeat::class);
        $seat->setDocument($documentUid);
        $seat->setFeUser($feUserUid);
        $seat->setAcquiredAt(time());
        $seat->setExpiresAt(time() + $this->seatTtlSeconds);

        $this->lendingSeatRepository->add($seat);
        $this->persistenceManager->persistAll();

        $this->logger->info(
            'LendingManagerService: seat acquired for user ' . $feUserUid . ' on document ' . $documentUid
            . ' (' . ($activeCount + 1) . '/' . $concurrentLimit . ' seats used)'
        );

        return $seat;
    }

    /**
     * Explicitly releases a user's seat for a document.
     *
     * Called when the user navigates away or explicitly closes the viewer.
     * This is a best-effort operation — if the seat has already expired or
     * was never created, the call is silently ignored.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID
     *
     * @return void
     */
    public function releaseSeat(int $documentUid, int $feUserUid): void
    {
        $seat = $this->lendingSeatRepository->findByDocumentAndUser($documentUid, $feUserUid);

        if ($seat === null) {
            $this->logger->debug(
                'LendingManagerService: releaseSeat called but no seat found for user '
                . $feUserUid . ' on document ' . $documentUid
            );
            return;
        }

        $this->lendingSeatRepository->remove($seat);
        $this->persistenceManager->persistAll();

        $this->logger->info(
            'LendingManagerService: seat released for user ' . $feUserUid . ' on document ' . $documentUid
        );
    }

    /**
     * Returns the number of currently active (non-expired) seats for a document.
     *
     * Used by the LendingController to render the queue UI showing how many
     * seats are free and how many readers are waiting.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     *
     * @return int
     */
    public function getActiveCount(int $documentUid): int
    {
        $this->purgeExpiredSeats($documentUid);
        return $this->lendingSeatRepository->countActiveByDocument($documentUid);
    }

    /**
     * Returns whether a specific user currently holds a seat for a document.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID
     *
     * @return bool
     */
    public function hasSeat(int $documentUid, int $feUserUid): bool
    {
        return $this->lendingSeatRepository->findByDocumentAndUser($documentUid, $feUserUid) !== null;
    }

    /**
     * Removes all expired seat records for a document.
     *
     * Called lazily on every acquireSeat() for the same document. This keeps
     * the tx_dlf_lending_seats table lean without needing a scheduler task,
     * at the cost of a small DELETE query on every seat acquisition.
     *
     * @access private
     *
     * @param int $documentUid The tx_dlf_documents UID
     *
     * @return void
     */
    private function purgeExpiredSeats(int $documentUid): void
    {
        $expired = $this->lendingSeatRepository->findExpiredByDocument($documentUid, time());

        foreach ($expired as $seat) {
            $this->lendingSeatRepository->remove($seat);
        }

        if (count($expired) > 0) {
            $this->persistenceManager->persistAll();
            $this->logger->debug(
                'LendingManagerService: purged ' . count($expired) . ' expired seat(s) for document ' . $documentUid
            );
        }
    }
}
