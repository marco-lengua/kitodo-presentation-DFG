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
 * Domain model for a document access policy.
 *
 * One row in tx_dlf_access_policies represents the parsed access rules for
 * a single document. Records are written exclusively by SLUB's LibRML parser
 * service on METS ingest and read exclusively by PolicyDecisionService (SUB)
 * at request time. No controller or Fluid template should ever access this
 * model directly.
 *
 * The rights_json column stores the full parsed LibRML rule set as a JSON
 * object. PolicyDecisionService reads it via getRightsJson() and evaluates
 * the rules without re-parsing XML. The expected top-level keys are:
 *
 *   category        string   'open' | 'ip' | 'role' | 'restricted'
 *   embargoDate     string   ISO-8601 date string, or empty
 *   ipRanges        string[] CIDR notation, e.g. ["134.100.0.0/16"]
 *   feGroups        int[]    tx_dlf_... or fe_groups UIDs
 *   concurrentLimit int      0 = no concurrent limit
 *   allowPrint      bool
 *   allowDownload   bool
 *   basePath        string   Image server path prefix, e.g. "/files/42/"
 *
 * The mets_hash field is a SHA-256 hash of the METS rights fields computed
 * at parse time. The ingest pipeline compares it on re-index to detect
 * whether the rights section has changed and a re-parse is needed.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class AccessPolicy extends AbstractEntity
{
    /**
     * UID of the document this policy applies to.
     * Foreign key → tx_dlf_documents.uid
     *
     * @access protected
     * @var int
     */
    protected $document;

    /**
     * JSON-encoded rights rules as written by the LibRML parser.
     * Stored as a string in PHP; use getRightsJson() to get the decoded array.
     *
     * @access protected
     * @var string
     */
    protected $rightsJson;

    /**
     * SHA-256 hash of the METS rights fields at parse time.
     * Used by the ingest pipeline to detect changes; not used by the PDP.
     *
     * @access protected
     * @var string
     */
    protected $metsHash;

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
     * Returns the decoded rights JSON as an associative array.
     *
     * Returns an empty array if the stored value is null, empty, or not
     * valid JSON, so callers can always do array access without null checks.
     *
     * @access public
     *
     * @return mixed[]
     */
    public function getRightsJson(): array
    {
        if (empty($this->rightsJson)) {
            return [];
        }

        $decoded = json_decode($this->rightsJson, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Stores the rights rules by JSON-encoding the provided array.
     *
     * Called by the LibRML parser after parsing the METS accessCondition.
     * The PDP never calls this setter — it only reads via getRightsJson().
     *
     * @access public
     *
     * @param mixed[] $rights The decoded rights array to store
     *
     * @return void
     */
    public function setRightsJson(array $rights): void
    {
        $this->rightsJson = (string) json_encode($rights);
    }

    /**
     * @access public
     *
     * @return string
     */
    public function getMetsHash(): string
    {
        return (string) $this->metsHash;
    }

    /**
     * @access public
     *
     * @param string $metsHash SHA-256 hex string
     *
     * @return void
     */
    public function setMetsHash(string $metsHash): void
    {
        $this->metsHash = $metsHash;
    }
}
