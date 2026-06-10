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

use Kitodo\Dlf\Domain\Model\AccessPolicy;

/**
 * Repository for AccessPolicy domain objects.
 *
 * The primary method used by PolicyDecisionService is findByDocument(), which
 * returns the policy for a single document UID. All other reads go through
 * the standard Extbase findBy* methods inherited from AbstractRepository.
 *
 * Write operations (add, update) are performed by SLUB's LibRML parser service
 * and are outside the scope of this repository's documentation.
 *
 * Storage page handling: access policies are written with whatever pid the
 * LibRML parser is configured to use. PolicyDecisionService must be able to
 * find them regardless of the storagePid setting of the calling plugin, so
 * findByDocument() explicitly disables the storagePid constraint.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 *
 * @extends AbstractRepository<AccessPolicy>
 */
class AccessPolicyRepository extends AbstractRepository
{
    /**
     * Finds the access policy for a given document UID.
     *
     * Returns null if no policy has been recorded for the document, which
     * PolicyDecisionService interprets as open access (no restrictions).
     *
     * The storagePid constraint is disabled here because access policies may
     * be stored on a different page than the document records, depending on
     * the library's TYPO3 page tree setup. The document UID is a globally
     * unique integer and does not need the storagePid as a discriminator.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     *
     * @return AccessPolicy|null
     */
    public function findByDocument(int $documentUid): ?AccessPolicy
    {
        $query = $this->createQuery();

        // Disable storagePid — policies live wherever the LibRML parser wrote them.
        $query->getQuerySettings()->setRespectStoragePage(false);

        $query->matching(
            $query->equals('document', $documentUid)
        );

        $query->setLimit(1);

        /** @var AccessPolicy|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Checks whether a policy record exists for a document without loading
     * the full object. Used by the ingest pipeline to decide insert vs. update.
     *
     * @access public
     *
     * @param int $documentUid The tx_dlf_documents UID
     *
     * @return bool
     */
    public function existsForDocument(int $documentUid): bool
    {
        return $this->findByDocument($documentUid) !== null;
    }
}
