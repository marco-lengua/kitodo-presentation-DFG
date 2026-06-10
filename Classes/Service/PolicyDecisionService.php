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

use Kitodo\Dlf\Domain\Model\AccessPolicy;
use Kitodo\Dlf\Domain\Repository\AccessPolicyRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Policy Decision Point (PDP) — the single place in Kitodo.Presentation that
 * decides whether a frontend user may access a document.
 *
 * All controllers that need access control inject this service and call
 * evaluate(). They never inspect AccessPolicy objects or fe_users directly.
 *
 * Decision flow for a given (document, user) pair:
 *
 *   1. If no AccessPolicy exists for the document → open access (AccessVerdict::REASON_OPEN).
 *   2. Evaluate embargo date: if today < embargoDate → deny (AccessVerdict::REASON_EMBARGO).
 *   3. Evaluate rule category in order: open → ip → role.
 *      - 'open'  → allow unconditionally.
 *      - 'ip'    → allow if client IP is inside a configured range.
 *      - 'role'  → allow if the fe_user belongs to a required FE usergroup.
 *   4. If a concurrentLimit > 0 is set, acquire a seat via LendingManagerService.
 *      If no seat is available → deny (AccessVerdict::REASON_SEAT_LIMIT).
 *   5. Issue a signed JWT via JwtTokenService and return an allowed verdict.
 *
 * Results are cached per (documentUid, feUserUid) pair within the same PHP
 * request so repeated calls from multiple plugins on the same page (e.g.
 * PageViewController + ToolboxController) do not hit the database twice.
 *
 * This service is registered as shared: true in Configuration/Services.yaml
 * so the TYPO3 DI container creates exactly one instance per request.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class PolicyDecisionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @access protected
     * @var AccessPolicyRepository
     */
    protected AccessPolicyRepository $accessPolicyRepository;

    /**
     * @access protected
     * @var JwtTokenService
     */
    protected JwtTokenService $jwtTokenService;

    /**
     * @access protected
     * @var LendingManagerService
     */
    protected LendingManagerService $lendingManagerService;

    /**
     * Per-request verdict cache keyed by "documentUid:feUserUid".
     * Prevents repeated DB lookups when multiple plugins render on the same page.
     *
     * @access private
     * @var array<string, AccessVerdict>
     */
    private array $verdictCache = [];

    /**
     * @access public
     *
     * @param AccessPolicyRepository $accessPolicyRepository
     *
     * @return void
     */
    public function injectAccessPolicyRepository(AccessPolicyRepository $accessPolicyRepository): void
    {
        $this->accessPolicyRepository = $accessPolicyRepository;
    }

    /**
     * @access public
     *
     * @param JwtTokenService $jwtTokenService
     *
     * @return void
     */
    public function injectJwtTokenService(JwtTokenService $jwtTokenService): void
    {
        $this->jwtTokenService = $jwtTokenService;
    }

    /**
     * @access public
     *
     * @param LendingManagerService $lendingManagerService
     *
     * @return void
     */
    public function injectLendingManagerService(LendingManagerService $lendingManagerService): void
    {
        $this->lendingManagerService = $lendingManagerService;
    }

    /**
     * Evaluates whether the current frontend user may access a document.
     *
     * This is the only public API of this service. Controllers call this
     * method and act on the returned AccessVerdict without knowing anything
     * about the rights database, LibRML categories, or lending seats.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID to evaluate
     *
     * @return AccessVerdict The access decision including reason and JWT if allowed
     */
    public function evaluate(int $documentUid): AccessVerdict
    {
        $feUserUid = $this->getCurrentFeUserUid();
        $cacheKey = $documentUid . ':' . $feUserUid;

        if (isset($this->verdictCache[$cacheKey])) {
            return $this->verdictCache[$cacheKey];
        }

        $verdict = $this->computeVerdict($documentUid, $feUserUid);
        $this->verdictCache[$cacheKey] = $verdict;

        return $verdict;
    }

    /**
     * Runs the full policy evaluation for a (document, user) pair.
     *
     * @access private
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID; 0 = anonymous
     *
     * @return AccessVerdict
     */
    private function computeVerdict(int $documentUid, int $feUserUid): AccessVerdict
    {
        // Step 1: load the access policy for this document.
        // The AccessPolicyRepository is provided by SLUB; it reads from
        // tx_dlf_access_policies which is populated by the LibRML parser.
        $policy = $this->accessPolicyRepository->findByDocument($documentUid);

        if ($policy === null) {
            // No restrictions recorded for this document → serve openly.
            $this->logger->debug('PDP: no policy found for document ' . $documentUid . ', granting open access');
            return $this->buildAllowedVerdict($documentUid, $feUserUid, $policy);
        }

        $rights = $policy->getRightsJson();

        // Step 2: embargo check — applies regardless of user identity.
        if ($this->isEmbargoActive($rights)) {
            $this->logger->info('PDP: document ' . $documentUid . ' is under embargo, denying access');
            return new AccessVerdict(false, AccessVerdict::REASON_EMBARGO, null);
        }

        // Step 3: evaluate the access rule in order of permissiveness.
        $category = $rights['category'] ?? 'restricted';

        if ($category === 'open') {
            return $this->buildAllowedVerdict($documentUid, $feUserUid, $policy);
        }

        if ($category === 'ip' && $this->isClientIpAllowed($rights)) {
            return $this->buildAllowedVerdict($documentUid, $feUserUid, $policy);
        }

        if ($category === 'role' && $this->feUserHasRequiredGroup($rights, $feUserUid)) {
            return $this->buildAllowedVerdict($documentUid, $feUserUid, $policy);
        }

        $this->logger->info(
            'PDP: access denied for user ' . $feUserUid . ' on document ' . $documentUid
            . ' (category: ' . $category . ')'
        );

        return new AccessVerdict(false, AccessVerdict::REASON_RESTRICTED, null);
    }

    /**
     * Builds an allowed verdict, handling the concurrent seat check if needed
     * and issuing the JWT for the image server.
     *
     * @access private
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID
     * @param AccessPolicy|null $policy The policy, or null for open documents
     *
     * @return AccessVerdict
     */
    private function buildAllowedVerdict(int $documentUid, int $feUserUid, ?AccessPolicy $policy): AccessVerdict
    {
        $rights = $policy?->getRightsJson() ?? [];

        // Step 4: concurrent seat limit — only checked for authenticated users.
        $concurrentLimit = (int) ($rights['concurrentLimit'] ?? 0);
        if ($concurrentLimit > 0) {
            $seat = $this->lendingManagerService->acquireSeat($documentUid, $feUserUid, $concurrentLimit);
            if ($seat === null) {
                $this->logger->info(
                    'PDP: seat limit reached for document ' . $documentUid . ', user ' . $feUserUid
                );
                return new AccessVerdict(false, AccessVerdict::REASON_SEAT_LIMIT, null);
            }
        }

        // Step 5: issue the JWT.
        // The 'paths' claim uses a single directory prefix that covers all
        // tiles for this document (e.g. "/files/42/"). The image server's
        // Apache module checks that the requested tile URL starts with one
        // of these prefixes, so a token for document 42 cannot be used to
        // access tiles from document 43.
        $allowedPaths = $this->buildAllowedPaths($documentUid, $rights);

        $flags = [
            'view'     => true,
            'print'    => (bool) ($rights['allowPrint']    ?? false),
            'download' => (bool) ($rights['allowDownload'] ?? false),
        ];

        $token = $this->jwtTokenService->issue($documentUid, $feUserUid, $allowedPaths, $flags);

        $this->logger->debug(
            'PDP: issued JWT for user ' . $feUserUid . ' on document ' . $documentUid
        );

        return new AccessVerdict(true, AccessVerdict::REASON_ALLOWED, $token, $flags);
    }

    /**
     * Checks whether the embargo date for a document has not yet passed.
     *
     * @access private
     *
     * @param mixed[] $rights The decoded rights JSON from AccessPolicy
     *
     * @return bool True if the embargo is still active (access should be denied)
     */
    private function isEmbargoActive(array $rights): bool
    {
        if (empty($rights['embargoDate'])) {
            return false;
        }

        $embargoTimestamp = strtotime($rights['embargoDate']);
        if ($embargoTimestamp === false) {
            $this->logger->warning('PDP: invalid embargoDate value: ' . $rights['embargoDate']);
            return false;
        }

        return time() < $embargoTimestamp;
    }

    /**
     * Checks whether the client's IP address is within any of the allowed ranges
     * defined in the access policy.
     *
     * IP ranges in the rights JSON are stored as CIDR notation strings, e.g.
     * ["134.100.0.0/16", "2001:db8::/32"]. This method delegates to TYPO3's
     * GeneralUtility::validIPv6() and GeneralUtility::cmpIP() to avoid
     * reimplementing CIDR matching.
     *
     * @access private
     *
     * @param mixed[] $rights The decoded rights JSON from AccessPolicy
     *
     * @return bool True if the client IP matches at least one allowed range
     */
    private function isClientIpAllowed(array $rights): bool
    {
        $allowedRanges = $rights['ipRanges'] ?? [];

        if (empty($allowedRanges)) {
            return false;
        }

        $clientIp = GeneralUtility::getIndpEnv('REMOTE_ADDR');

        foreach ($allowedRanges as $range) {
            if (GeneralUtility::cmpIP($clientIp, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether the current fe_user belongs to at least one of the
     * FE usergroups required by the access policy.
     *
     * Required groups in the rights JSON are stored as an array of
     * tx_dlf_... or TYPO3 fe_groups UIDs, e.g. [3, 7, 12]. The mapping
     * from LibRML user-group names to TYPO3 FE usergroup UIDs is performed
     * by the LibRML parser (SLUB component) at ingest time.
     *
     * @access private
     *
     * @param mixed[] $rights The decoded rights JSON from AccessPolicy
     * @param int $feUserUid The fe_users UID to check; 0 = anonymous
     *
     * @return bool True if the user is in at least one required group
     */
    private function feUserHasRequiredGroup(array $rights, int $feUserUid): bool
    {
        if ($feUserUid <= 0) {
            return false;
        }

        $requiredGroups = $rights['feGroups'] ?? [];

        if (empty($requiredGroups)) {
            return false;
        }

        try {
            // TYPO3 Context API provides the frontend user's group list
            // without touching the session directly.
            /** @var Context $context */
            $context = GeneralUtility::makeInstance(Context::class);
            $userGroups = $context->getPropertyFromAspect('frontend.user', 'groupIds');
        } catch (AspectNotFoundException $e) {
            $this->logger->warning('PDP: could not read frontend user groups from Context: ' . $e->getMessage());
            return false;
        }

        foreach ($requiredGroups as $requiredGroup) {
            if (in_array((int) $requiredGroup, $userGroups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the list of allowed path prefixes to embed in the JWT.
     *
     * Uses a single directory prefix per document rather than enumerating
     * individual page files. This keeps the JWT compact (well under 4 KB)
     * regardless of how many pages the document has.
     *
     * The base path pattern is configurable via the rights JSON 'basePath'
     * field, which the LibRML parser sets at ingest time. If not set, a
     * conventional default of "/files/{documentUid}/" is used.
     *
     * @access private
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param mixed[] $rights The decoded rights JSON from AccessPolicy
     *
     * @return string[] Array of allowed path prefixes
     */
    private function buildAllowedPaths(int $documentUid, array $rights): array
    {
        if (!empty($rights['basePath'])) {
            return [$rights['basePath']];
        }

        // Conventional fallback: all files under /files/{uid}/
        return ['/files/' . $documentUid . '/'];
    }

    /**
     * Returns the UID of the currently logged-in frontend user.
     *
     * Returns 0 for anonymous / unauthenticated users. Uses the TYPO3
     * Context API rather than $GLOBALS['TSFE']->fe_user directly, which
     * is the correct approach for TYPO3 v12+.
     *
     * @access private
     *
     * @return int fe_users UID, or 0 if not logged in
     */
    private function getCurrentFeUserUid(): int
    {
        try {
            /** @var Context $context */
            $context = GeneralUtility::makeInstance(Context::class);
            return (int) $context->getPropertyFromAspect('frontend.user', 'id');
        } catch (AspectNotFoundException $e) {
            $this->logger->debug('PDP: could not read frontend user UID from Context: ' . $e->getMessage());
            return 0;
        }
    }
}
