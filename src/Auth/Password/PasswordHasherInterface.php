<?php

declare(strict_types=1);

namespace Pulsar\Auth\Password;

use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * Contract for password hashing operations.
 *
 * An implementation must consume the whole password: no algorithm that truncates
 * its input, as bcrypt does at 72 bytes, satisfies this contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface PasswordHasherInterface
{
    /**
     * Shortest password any implementation will hash.
     *
     * The framework floor, not the policy: surfaces that resolve a configured
     * minimum (ASVS 4.0.3 §2.1.1 asks for 12) enforce that instead, and may only
     * raise this number.
     */
    public const int MIN_LENGTH = 8;

    /**
     * Longest password any implementation will hash or verify.
     *
     * ASVS 4.0.3 §2.1.2 requires at least 64 characters to be accepted; the cap
     * exists only to bound the work one request can ask of the hasher.
     */
    public const int MAX_LENGTH = 4_096;

    /**
     * Hash a plaintext password.
     *
     * @throws InvalidArgumentException if the password falls outside the length
     *                                  policy the implementation enforces. Callers
     *                                  taking user input validate it first, so this
     *                                  signals a bypassed surface, not a bad password.
     */
    public function hash(string $password): string;

    /**
     * Verify a plaintext password against a hash.
     *
     * Returns false — never throws — for a candidate outside the length policy.
     */
    public function verify(string $password, string $hash): bool;

    /**
     * Check if a hash needs to be rehashed (e.g., algorithm or cost changed).
     */
    public function needsRehash(string $hash): bool;
}
