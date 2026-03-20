<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

/**
 * Repository for WebAuthn public key credential storage.
 *
 * Stores credential sources (public keys, counters, transports) associated
 * with user accounts. Supports multiple credentials per user.
 */
#[Api(since: '1.0.0')]
interface CredentialRepositoryInterface
{
    /**
     * Find a credential by its raw credential ID.
     */
    public function findById(string $credentialId): ?CredentialSource;

    /**
     * Find all credentials for a user.
     *
     * @return list<CredentialSource>
     */
    public function findByUserId(string $userId): array;

    /**
     * Persist a new credential source.
     */
    public function persist(CredentialSource $credential): void;

    /**
     * Update the signature counter for a credential.
     *
     * Called after each successful authentication to track counter progression.
     * Used for clone detection (counter should always increase).
     */
    public function updateCounter(string $credentialId, int $newCounter): void;

    /**
     * Remove a credential.
     */
    public function remove(string $credentialId): void;

    /**
     * Remove all credentials for a user.
     */
    public function removeByUserId(string $userId): void;

    /**
     * Check if a credential ID is already registered.
     */
    public function exists(string $credentialId): bool;
}
