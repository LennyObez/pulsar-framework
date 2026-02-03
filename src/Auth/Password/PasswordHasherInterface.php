<?php

declare(strict_types=1);

namespace Pulsar\Auth\Password;

/**
 * Contract for password hashing operations.
 */
interface PasswordHasherInterface
{
    /**
     * Hash a plaintext password.
     */
    public function hash(string $password): string;

    /**
     * Verify a plaintext password against a hash.
     */
    public function verify(string $password, string $hash): bool;

    /**
     * Check if a hash needs to be rehashed (e.g., algorithm or cost changed).
     */
    public function needsRehash(string $hash): bool;
}
