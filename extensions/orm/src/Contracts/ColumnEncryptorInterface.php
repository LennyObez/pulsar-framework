<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;

/**
 * Encrypts/decrypts column values for at-rest encryption.
 */
#[Api(since: '1.0.0')]
interface ColumnEncryptorInterface
{
    /**
     * Encrypt a plaintext column value.
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt an encrypted column value.
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Compute a blind index hash for equality lookups on encrypted data.
     */
    public function blindIndex(string $plaintext, int $hashLength = 32): string;
}
