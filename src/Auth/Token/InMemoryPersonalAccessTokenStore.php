<?php

declare(strict_types=1);

namespace Pulsar\Auth\Token;

use DateTimeImmutable;
use Pulsar\Api\Internal;

use function hash_equals;

/**
 * In-memory implementation of the personal access token store.
 *
 * Suitable for testing and development. Production deployments
 * should use a database-backed implementation.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class InMemoryPersonalAccessTokenStore implements PersonalAccessTokenStoreInterface
{
    /** @var array<string, PersonalAccessToken> Indexed by token ID */
    private array $tokens = [];

    public function save(PersonalAccessToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function findByHash(string $tokenHash): ?PersonalAccessToken
    {
        foreach ($this->tokens as $token) {
            if (hash_equals($token->tokenHash, $tokenHash)) {
                return $token;
            }
        }

        return null;
    }

    /** @return list<PersonalAccessToken> */
    public function findByUser(string $userId): array
    {
        $result = [];

        foreach ($this->tokens as $token) {
            if ($token->userId === $userId) {
                $result[] = $token;
            }
        }

        return $result;
    }

    public function delete(string $tokenId): bool
    {
        if (!isset($this->tokens[$tokenId])) {
            return false;
        }

        unset($this->tokens[$tokenId]);

        return true;
    }

    public function touchLastUsed(string $tokenId): void
    {
        if (!isset($this->tokens[$tokenId])) {
            return;
        }

        $existing = $this->tokens[$tokenId];

        $this->tokens[$tokenId] = new PersonalAccessToken(
            id: $existing->id,
            userId: $existing->userId,
            name: $existing->name,
            tokenHash: $existing->tokenHash,
            prefix: $existing->prefix,
            scopes: $existing->scopes,
            createdAt: $existing->createdAt,
            expiresAt: $existing->expiresAt,
            lastUsedAt: new DateTimeImmutable(),
        );
    }
}
