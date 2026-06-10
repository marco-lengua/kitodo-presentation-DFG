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

namespace Kitodo\Dlf\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for a concurrent lending seat.
 *
 * One row in tx_dlf_lending_seats represents one active reading session
 * for a document. LendingManagerService creates, renews, and removes these
 * records; it is the only place that should touch them.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class LendingSeat extends AbstractEntity
{
    /**
     * UID of the document being read.
     * Foreign key → tx_dlf_documents.uid
     *
     * @access protected
     * @var int
     */
    protected $document;

    /**
     * UID of the frontend user holding the seat.
     * Foreign key → fe_users.uid
     *
     * @access protected
     * @var int
     */
    protected $feUser;

    /**
     * Unix timestamp when the seat was first acquired.
     *
     * @access protected
     * @var int
     */
    protected $acquiredAt;

    /**
     * Unix timestamp after which the seat is considered expired.
     * The LendingManagerService updates this on every token refresh
     * to extend the session as long as the user is actively reading.
     *
     * @access protected
     * @var int
     */
    protected $expiresAt;

    /**
     * @access public
     *
     * @return int
     */
    public function getDocument(): int
    {
        return (int) $this->document;
    }

    /**
     * @access public
     *
     * @param int $document
     *
     * @return void
     */
    public function setDocument(int $document): void
    {
        $this->document = $document;
    }

    /**
     * @access public
     *
     * @return int
     */
    public function getFeUser(): int
    {
        return (int) $this->feUser;
    }

    /**
     * @access public
     *
     * @param int $feUser
     *
     * @return void
     */
    public function setFeUser(int $feUser): void
    {
        $this->feUser = $feUser;
    }

    /**
     * @access public
     *
     * @return int
     */
    public function getAcquiredAt(): int
    {
        return (int) $this->acquiredAt;
    }

    /**
     * @access public
     *
     * @param int $acquiredAt Unix timestamp
     *
     * @return void
     */
    public function setAcquiredAt(int $acquiredAt): void
    {
        $this->acquiredAt = $acquiredAt;
    }

    /**
     * @access public
     *
     * @return int
     */
    public function getExpiresAt(): int
    {
        return (int) $this->expiresAt;
    }

    /**
     * @access public
     *
     * @param int $expiresAt Unix timestamp
     *
     * @return void
     */
    public function setExpiresAt(int $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /**
     * Returns true if the seat's expiry time is in the past.
     *
     * @access public
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return time() > $this->getExpiresAt();
    }
}
