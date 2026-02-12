<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use Pulsar\Api\Api;

/**
 * Storage contract for pseudonym mappings.
 *
 * Implementations provide persistence for the bidirectional mapping
 * between subject identifiers and their pseudonyms. This contract
 * supports controls for GDPR right-to-erasure by enabling targeted
 * deletion of individual mappings.
 */
#[Api(since: '1.0.0')]
interface PseudonymLookupInterface
{
    /**
     * Store a new pseudonym mapping.
     */
    public function store(string $subjectId, string $pseudonym, string $encryptedSalt): void;

    /**
     * Find a mapping by its subject identifier.
     */
    public function findBySubjectId(string $subjectId): ?PseudonymMapping;

    /**
     * Find a mapping by its pseudonym value.
     */
    public function findByPseudonym(string $pseudonym): ?PseudonymMapping;

    /**
     * Delete the mapping for a given subject identifier.
     *
     * @return bool True if a mapping was deleted, false if none existed
     */
    public function delete(string $subjectId): bool;
}
