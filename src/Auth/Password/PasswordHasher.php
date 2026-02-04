<?php

declare(strict_types=1);

namespace Pulsar\Auth\Password;

use const PASSWORD_ARGON2ID;

use function password_hash;
use function password_needs_rehash;
use function password_verify;

/**
 * Argon2id password hasher using PHP's built-in password_hash().
 */
final readonly class PasswordHasher implements PasswordHasherInterface
{
    /**
     * @param array<string, int> $options Argon2id options (memory_cost, time_cost, threads)
     */
    public function __construct(
        private array $options = [],
    ) {}

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
