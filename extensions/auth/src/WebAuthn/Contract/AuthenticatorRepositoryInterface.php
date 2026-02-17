<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorRecord;

/**
 * Repository for authenticator metadata and management.
 *
 * Provides CRUD operations for managing registered authenticators,
 * including human-friendly naming and listing.
 */
#[Api(since: '1.0.0')]
interface AuthenticatorRepositoryInterface
{
    /**
     * Find an authenticator record by credential ID.
     */
    public function findByCredentialId(string $credentialId): ?AuthenticatorRecord;

    /**
     * List all authenticators for a user.
     *
     * @return list<AuthenticatorRecord>
     */
    public function listByUserId(string $userId): array;

    /**
     * Register a new authenticator.
     */
    public function register(AuthenticatorRecord $record): void;

    /**
     * Update an authenticator's display name.
     */
    public function rename(string $credentialId, string $newName): void;

    /**
     * Revoke (deactivate) an authenticator.
     *
     * Does not delete the record; maintains audit trail.
     */
    public function revoke(string $credentialId): void;

    /**
     * Count active authenticators for a user.
     */
    public function countActive(string $userId): int;
}
