<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;
use SensitiveParameter;

/**
 * Persistence contract for TOTP shared secrets.
 *
 * Implementations must encrypt secrets at rest. The framework provides
 * InMemoryTotpSecretStore for testing; production deployments bind a
 * database-backed implementation.
 */
#[Api(since: '1.0.0')]
interface TotpSecretStoreInterface
{
    /**
     * Store an encrypted TOTP secret for an identity.
     *
     * @param string $identityId The identity owning this secret
     * @param string $secret The raw binary TOTP secret to encrypt and store
     */
    public function store(string $identityId, #[SensitiveParameter] string $secret): void;

    /**
     * Retrieve and decrypt a TOTP secret for an identity.
     *
     * @param string $identityId The identity owning this secret
     * @return string|null Decrypted raw secret, or null if not found
     */
    public function retrieve(string $identityId): ?string;

    /**
     * Delete a stored TOTP secret.
     *
     * @param string $identityId The identity owning this secret
     */
    public function delete(string $identityId): void;
}
