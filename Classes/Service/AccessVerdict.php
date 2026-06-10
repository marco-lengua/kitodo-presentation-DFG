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

/**
 * Immutable value object representing the outcome of a PDP evaluation.
 *
 * Controllers receive this object from PolicyDecisionService::evaluate()
 * and act on it without knowing the details of how the decision was made.
 * The object is intentionally read-only after construction.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class AccessVerdict
{
    /**
     * Document has no access restrictions.
     *
     * @access public
     * @var string
     */
    const REASON_OPEN = 'open';

    /**
     * Access was explicitly allowed by the evaluated policy.
     *
     * @access public
     * @var string
     */
    const REASON_ALLOWED = 'allowed';

    /**
     * Access denied: the document is restricted and the user's identity
     * or IP does not satisfy the required access category.
     *
     * @access public
     * @var string
     */
    const REASON_RESTRICTED = 'restricted';

    /**
     * Access denied: the document's embargo date has not yet passed.
     *
     * @access public
     * @var string
     */
    const REASON_EMBARGO = 'embargo';

    /**
     * Access denied: all concurrent lending seats for this document are
     * currently occupied. The user should be shown a waitlist UI.
     *
     * @access public
     * @var string
     */
    const REASON_SEAT_LIMIT = 'seat_limit';

    /**
     * @access private
     * @var bool
     */
    private bool $allowed;

    /**
     * @access private
     * @var string
     */
    private string $reason;

    /**
     * The signed JWT to embed in the page for image server access.
     * Null when access is denied.
     *
     * @access private
     * @var string|null
     */
    private ?string $token;

    /**
     * Permission flags from the access policy.
     * Keys: 'view', 'print', 'download'. All false when access is denied.
     *
     * @access private
     * @var bool[]
     */
    private array $flags;

    /**
     * @access public
     *
     * @param bool $allowed Whether access is granted
     * @param string $reason One of the REASON_* constants
     * @param string|null $token Signed JWT, or null if denied
     * @param bool[] $flags Permission flags: ['view' => bool, 'print' => bool, 'download' => bool]
     */
    public function __construct(bool $allowed, string $reason, ?string $token, array $flags = [])
    {
        $this->allowed = $allowed;
        $this->reason  = $reason;
        $this->token   = $token;
        $this->flags   = array_merge(
            ['view' => false, 'print' => false, 'download' => false],
            $flags
        );
    }

    /**
     * @access public
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * @access public
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @access public
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Returns a single named permission flag.
     *
     * @access public
     *
     * @param string $name One of: 'view', 'print', 'download'
     *
     * @return bool
     */
    public function getFlag(string $name): bool
    {
        return (bool) ($this->flags[$name] ?? false);
    }

    /**
     * Returns all permission flags as an array.
     *
     * @access public
     *
     * @return bool[]
     */
    public function getFlags(): array
    {
        return $this->flags;
    }
}
