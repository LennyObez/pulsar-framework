<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\RefreshTokenRepositoryInterface;

use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_generichash;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;

/**
 * In-memory refresh token repository with rotation and replay detection.
 *
 * SEC-CRYPTO-01: BLAKE2b keyed (libsodium) index, per-instance random key.
 * When a consumed (rotated-out) token is reused, the entire token family is
 * revoked as a breach indicator.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    /** @var array<string, RefreshToken> Keyed by token ID */
    private array $tokensById = [];

    /** @var array<string, string> BLAKE2b-keyed(tokenValue) (hex) => token ID */
    private array $hashIndex = [];

    /** @var array<string, bool> Token IDs that have been revoked */
    private array $revoked = [];

    /** @var array<string, bool> Token IDs that have been consumed (rotated out) */
    private array $consumed = [];

    /** @var array<string, list<string>> Family ID => list of token IDs in the family */
    private array $families = [];

    /** @var bool Whether replay was detected on the last consume() call */
    private bool $lastConsumeWasReplay = false;

    private readonly string $indexKey;

    public function __construct()
    {
        $this->indexKey = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    }

    public function persist(RefreshToken $token): void
    {
        $this->tokensById[$token->id] = $token;
        $this->families[$token->familyId][] = $token->id;

        if ($token->tokenValue !== null) {
            $this->hashIndex[$this->hashTokenForIndex($token->tokenValue)] = $token->id;
        }
    }

    public function consume(string $tokenValue): ?RefreshToken
    {
        $this->lastConsumeWasReplay = false;

        $hash = $this->hashTokenForIndex($tokenValue);
        $tokenId = $this->hashIndex[$hash] ?? null;

        if ($tokenId === null) {
            return null;
        }

        $token = $this->tokensById[$tokenId] ?? null;

        if ($token === null) {
            return null;
        }

        // Replay detection: if this token was already consumed, revoke the entire family
        if (isset($this->consumed[$tokenId])) {
            $this->revokeFamily($token->familyId);
            $this->lastConsumeWasReplay = true;
            return null;
        }

        // Already revoked or expired
        if (isset($this->revoked[$tokenId]) || $token->isExpired()) {
            return null;
        }

        // Mark as consumed atomically
        $this->consumed[$tokenId] = true;
        $this->tokensById[$tokenId] = new RefreshToken(
            id: $token->id,
            clientId: $token->clientId,
            subjectId: $token->subjectId,
            sessionId: $token->sessionId,
            familyId: $token->familyId,
            scopes: $token->scopes,
            expiresAt: $token->expiresAt,
            issuedAt: $token->issuedAt,
            revoked: $token->revoked,
            consumed: true,
            tokenValue: $token->tokenValue,
        );

        return $token;
    }

    /**
     * Check if the last consume() call detected a replay attack.
     */
    public function wasReplayDetected(): bool
    {
        return $this->lastConsumeWasReplay;
    }

    public function revoke(string $tokenId): void
    {
        $this->revoked[$tokenId] = true;

        $existing = $this->tokensById[$tokenId] ?? null;
        if ($existing !== null) {
            $this->tokensById[$tokenId] = new RefreshToken(
                id: $existing->id,
                clientId: $existing->clientId,
                subjectId: $existing->subjectId,
                sessionId: $existing->sessionId,
                familyId: $existing->familyId,
                scopes: $existing->scopes,
                expiresAt: $existing->expiresAt,
                issuedAt: $existing->issuedAt,
                revoked: true,
                consumed: $existing->consumed,
                tokenValue: $existing->tokenValue,
            );
        }
    }

    public function revokeFamily(string $familyId): void
    {
        $tokenIds = $this->families[$familyId] ?? [];
        foreach ($tokenIds as $tokenId) {
            $this->revoke($tokenId);
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
