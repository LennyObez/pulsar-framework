<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * Storage backend for token-to-ciphertext mappings.
 *
 * Implementations persist the mapping between a generated token and the
 * encrypted representation of the original sensitive value.
 * @api
 */
#[Api(since: '1.0.0')]
interface TokenStoreInterface
{
    /**
     * Store a token mapping.
     *
     * @param string $token             The generated token (lookup key)
     * @param string $encryptedValue    The encrypted original value
     * @param string $context           The domain context (e.g. 'pan', 'ssn')
     */
    public function store(string $token, string $encryptedValue, string $context): void;

    /**
     * Retrieve the encrypted value for a token.
     *
     * @param string $token The token to look up
     *
     * @return string|null The encrypted value, or null if the token does not exist
     */
    public function retrieve(string $token): ?string;

    /**
     * Check whether a token exists in the store.
     *
     * @param string $token The token to check
     */
    public function exists(string $token): bool;

    /**
     * Remove a token mapping.
     *
     * @param string $token The token to remove
     */
    public function remove(string $token): void;
}
