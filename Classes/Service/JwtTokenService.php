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

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Issues and verifies HMAC-SHA256 signed JWT tokens for image server access.
 *
 * This service is the only place in the codebase that touches the shared
 * HMAC secret. It is registered as a shared (singleton) service in
 * Configuration/Services.yaml so the secret is read from the environment
 * exactly once per request.
 *
 * No external JWT library is used — the implementation follows RFC 7519
 * using PHP's native hash_hmac() and base64url encoding. The secret is
 * passed via the DLF_JWT_SECRET environment variable and injected through
 * Services.yaml; it is never stored in the database or in TypoScript.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class JwtTokenService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Fixed JWT header, base64url-encoded.
     * alg=HS256, typ=JWT — never changes, so we compute it once.
     *
     * @access private
     * @var string
     */
    private string $encodedHeader;

    /**
     * The HMAC-SHA256 shared secret read from the environment.
     *
     * @access private
     * @var string
     */
    private string $hmacSecret;

    /**
     * Token lifetime in seconds. Agreed in the DFG project meeting: ~15 min.
     *
     * @access private
     * @var int
     */
    private int $ttlSeconds;

    /**
     * @access public
     *
     * @param string $hmacSecret Injected via Services.yaml from env(DLF_JWT_SECRET)
     * @param int $ttlSeconds Token lifetime in seconds (default: 900 = 15 min)
     */
    public function __construct(string $hmacSecret, int $ttlSeconds = 900)
    {
        $this->hmacSecret = $hmacSecret;
        $this->ttlSeconds = $ttlSeconds;
        $this->encodedHeader = $this->base64UrlEncode(
            (string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'])
        );
    }

    /**
     * Issues a signed JWT token for a document access grant.
     *
     * The payload contains:
     *   doc   — tx_dlf_documents UID
     *   uid   — fe_users UID (0 for anonymous / IP-based access)
     *   paths — array of allowed path prefixes on the image server
     *   flags — permission booleans: view, print, download
     *   iat   — issued-at Unix timestamp
     *   exp   — expiry Unix timestamp (iat + ttlSeconds)
     *
     * The paths array uses directory prefixes, not per-file enumeration,
     * to keep the token well below the 4 KB best-practice limit agreed
     * in the project meeting. Example: ["/files/42/"] covers all tiles
     * for document 42 without listing each page file individually.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     * @param int $feUserUid The fe_users UID; 0 for unauthenticated access
     * @param string[] $allowedPaths Allowed path prefixes on the image server
     * @param bool[] $flags Permission flags: ['view' => true, 'print' => false, 'download' => false]
     *
     * @return string The compact JWT string (header.payload.signature)
     */
    public function issue(int $documentUid, int $feUserUid, array $allowedPaths, array $flags): string
    {
        $now = time();

        $payload = $this->base64UrlEncode(
            (string) json_encode([
                'doc'   => $documentUid,
                'uid'   => $feUserUid,
                'paths' => array_values($allowedPaths),
                'flags' => [
                    'view'     => (bool) ($flags['view']     ?? true),
                    'print'    => (bool) ($flags['print']    ?? false),
                    'download' => (bool) ($flags['download'] ?? false),
                ],
                'iat' => $now,
                'exp' => $now + $this->ttlSeconds,
            ])
        );

        $signature = $this->base64UrlEncode(
            (string) hash_hmac('sha256', $this->encodedHeader . '.' . $payload, $this->hmacSecret, true)
        );

        return $this->encodedHeader . '.' . $payload . '.' . $signature;
    }

    /**
     * Verifies a JWT token and returns its decoded claims.
     *
     * Returns null if the token is malformed, the signature does not match,
     * or the token has expired. hash_equals() is used for the signature
     * comparison to prevent timing attacks.
     *
     * @access public
     *
     * @param string $token The compact JWT string to verify
     *
     * @return mixed[]|null The decoded claims array, or null on any failure
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            $this->logger->warning('JWT verification failed: token does not have three parts');
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Recompute the expected signature from the received header + payload.
        // We use the received header (not our cached one) so that any header
        // tampering is caught by the HMAC mismatch, not a string comparison.
        $expectedSignature = $this->base64UrlEncode(
            (string) hash_hmac('sha256', $header . '.' . $payload, $this->hmacSecret, true)
        );

        // hash_equals() is constant-time — prevents timing oracle attacks
        // where an attacker measures response time to brute-force the secret.
        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->warning('JWT verification failed: signature mismatch');
            return null;
        }

        $claims = json_decode($this->base64UrlDecode($payload), true);

        if (!is_array($claims)) {
            $this->logger->warning('JWT verification failed: payload is not a JSON object');
            return null;
        }

        if (!isset($claims['exp']) || $claims['exp'] < time()) {
            $this->logger->info('JWT verification failed: token has expired');
            return null;
        }

        return $claims;
    }

    /**
     * Returns the token TTL in seconds (useful for Cache-Control headers).
     *
     * @access public
     *
     * @return int
     */
    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Encodes a string using base64url encoding (RFC 4648 §5).
     *
     * Base64url replaces + with - and / with _ and omits padding =,
     * making the result safe for use in URLs and HTTP headers without
     * further percent-encoding.
     *
     * @access private
     *
     * @param string $data Raw binary or text data to encode
     *
     * @return string Base64url-encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodes a base64url-encoded string (RFC 4648 §5).
     *
     * @access private
     *
     * @param string $data Base64url-encoded string
     *
     * @return string Decoded binary or text data
     */
    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
