<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use Pulsar\Api\Api;
use SodiumException;

/**
 * Contract for pseudonymizing subject identifiers.
 *
 * Provides forward and reverse mapping between real subject identifiers
 * and their pseudonyms. This contract supports controls for GDPR
 * Article 4(5) pseudonymization and HIPAA de-identification requirements.
 */
#[Api(since: '1.0.0')]
interface PseudonymizationServiceInterface
{
    /**
     * Generate or retrieve a pseudonym for the given subject identifier.
     *
     * If a mapping already exists, returns the existing pseudonym.
     * Otherwise, derives a new pseudonym using keyed hashing with a
     * random salt and stores the mapping.
     *
     * @throws SodiumException
     */
    public function pseudonymize(string $subjectId): string;

    /**
     * Reverse-lookup a subject identifier from its pseudonym.
     *
     * @return string|null The original subject identifier, or null if no mapping exists
     */
    public function resolve(string $pseudonym): ?string;

    /**
     * Check whether a pseudonym mapping exists for the given subject.
     */
    public function exists(string $subjectId): bool;
}
