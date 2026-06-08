<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;

use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_generichash;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;

/**
 * In-memory access token repository for testing and development.
 *
 * SEC-CRYPTO-01: Lookups use BLAKE2b keyed (libsodium) over the raw token,
 * not SHA-256 non-keyed. The index key is generated at construction time
 * and lives for the lifetime of the repository instance — appropriate for
 * the in-memory variant where the store itself dies with the process. A
 * memory dump from one instance cannot be replayed against another, and an
 * attacker who exfiltrates the index alone cannot iterate over a
 * pre-computed token dictionary.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryAccessTokenRepository implements AccessTokenRepositoryInterface
{
    /**
     * @var array<string, AccessToken> Keyed by token ID
     *
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private array $tokensById = [];

    /** @var array<string, string> BLAKE2b-keyed(tokenValue) (hex) => token ID */
    private array $hashIndex = [];

    /** @var array<string, bool> Token IDs that have been revoked */
    private array $revoked = [];

    private readonly string $indexKey;

    public function __construct()
    {
        $this->indexKey = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    }

    public function persist(AccessToken $token): void
    {
        $this->tokensById[$token->id] = $token;

        if ($token->tokenValue !== null) {
            $this->hashIndex[$this->hashTokenForIndex($token->tokenValue)] = $token->id;
        }
    }

    public function introspect(string $tokenValue): ?AccessToken
    {
        $hash = $this->hashTokenForIndex($tokenValue);
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

    private function hashTokenForIndex(string $tokenValue): string
    {
        return sodium_bin2hex(sodium_crypto_generichash($tokenValue, $this->indexKey, 32));
    }
}
