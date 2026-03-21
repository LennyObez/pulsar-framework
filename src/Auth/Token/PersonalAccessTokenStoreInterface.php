<?php

declare(strict_types=1);

namespace Pulsar\Auth\Token;

use Pulsar\Api\Api;

/**
 * Persistence contract for personal access tokens.
 * @api
 */
#[Api(since: '1.0.0')]
interface PersonalAccessTokenStoreInterface
{
    /**
     * Persist a new token.
     */
    public function save(PersonalAccessToken $token): void;

    /**
     * Find a token by its SHA-256 hash.
     */
    public function findByHash(string $tokenHash): ?PersonalAccessToken;

    /**
     * Find all tokens belonging to a user.
     *
     * @return list<PersonalAccessToken>
     */
    public function findByUser(string $userId): array;

    /**
     * Delete a token by ID.
     *
     * @return bool True if the token existed and was deleted
     */
    public function delete(string $tokenId): bool;

    /**
     * Update the last_used_at timestamp for a token.
     */
    public function touchLastUsed(string $tokenId): void;
}
