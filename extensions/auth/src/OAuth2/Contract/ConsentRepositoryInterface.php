<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Consent\ConsentRecord;

/**
 * Repository for user consent records.
 *
 * Tracks which scopes a user has granted to which clients.
 * Consent decisions are audit-logged.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConsentRepositoryInterface
{
    /**
     * Check if the user has previously consented to the requested scopes for this client.
     *
     * @param list<string> $scopes The requested scope identifiers
     * @return bool True if all requested scopes are covered by existing consent
     */
    public function hasConsent(string $subjectId, string $clientId, array $scopes): bool;

    /**
     * Record user consent for specific scopes.
     *
     * @param list<string> $scopes The consented scope identifiers
     */
    public function grantConsent(string $subjectId, string $clientId, array $scopes): ConsentRecord;

    /**
     * Revoke all consent for a specific client.
     */
    public function revokeConsent(string $subjectId, string $clientId): void;

    /**
     * List all active consent records for a subject.
     *
     * @return list<ConsentRecord>
     */
    public function listConsents(string $subjectId): array;
}
