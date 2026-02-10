<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;

/**
 * In-memory access token repository for testing and development.
 *
 * Tokens are stored hashed (SHA-256). Lookups use the hash of the raw value.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryAccessTokenRepository implements AccessTokenRepositoryInterface
{
    /** @var array<string, AccessToken> Keyed by token ID */
    private array $tokensById = [];

    /** @var array<string, string> Hash(tokenValue) => token ID */
    private array $hashIndex = [];

    /** @var array<string, bool> Token IDs that have been revoked */
    private array $revoked = [];

    public function persist(AccessToken $token): void
    {
        $this->tokensById[$token->id] = $token;

        if ($token->tokenValue !== null) {
            $hash = hash('sha256', $token->tokenValue);
            $this->hashIndex[$hash] = $token->id;
        }
    }

    public function introspect(string $tokenValue): ?AccessToken
    {
        $hash = hash('sha256', $tokenValue);
        $tokenId = $this->hashIndex[$hash] ?? null;

        if ($tokenId === null) {
            return null;
        }

        $token = $this->tokensById[$tokenId] ?? null;

        if ($token === null || !$token->isActive()) {
            return null;
        }

        if (isset($this->revoked[$tokenId])) {
            return null;
        }

        return $token;
    }

    public function revoke(string $tokenId): void
    {
        $this->revoked[$tokenId] = true;

        $existing = $this->tokensById[$tokenId] ?? null;
        if ($existing !== null) {
            $this->tokensById[$tokenId] = new AccessToken(
                id: $existing->id,
                clientId: $existing->clientId,
                subjectId: $existing->subjectId,
                scopes: $existing->scopes,
                expiresAt: $existing->expiresAt,
                issuedAt: $existing->issuedAt,
                revoked: true,
                tokenValue: $existing->tokenValue,
            );
        }
    }

    public function revokeBySubject(string $subjectId): void
    {
        foreach ($this->tokensById as $token) {
            if ($token->subjectId === $subjectId) {
                $this->revoke($token->id);
            }
        }
    }

    public function isRevoked(string $tokenId): bool
    {
        return isset($this->revoked[$tokenId]);
    }
}
