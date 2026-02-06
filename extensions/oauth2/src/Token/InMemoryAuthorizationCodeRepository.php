<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\OAuth2\Contract\AuthorizationCodeRepositoryInterface;

/**
 * In-memory authorization code repository.
 *
 * Codes are stored hashed (SHA-256) and are one-time use with atomic consumption.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryAuthorizationCodeRepository implements AuthorizationCodeRepositoryInterface
{
    /** @var array<string, AuthorizationCode> Keyed by code ID */
    private array $codesById = [];

    /** @var array<string, string> Hash(codeValue) => code ID */
    private array $hashIndex = [];

    /** @var array<string, bool> Code IDs that have been revoked */
    private array $revoked = [];

    /** @var array<string, bool> Code IDs that have been consumed */
    private array $consumed = [];

    public function persist(AuthorizationCode $code): void
    {
        $this->codesById[$code->id] = $code;

        if ($code->codeValue !== null) {
            $hash = hash('sha256', $code->codeValue);
            $this->hashIndex[$hash] = $code->id;
        }
    }

    public function consume(string $codeValue): ?AuthorizationCode
    {
        $hash = hash('sha256', $codeValue);
        $codeId = $this->hashIndex[$hash] ?? null;

        if ($codeId === null) {
            return null;
        }

        // Already consumed or revoked
        if (isset($this->consumed[$codeId]) || isset($this->revoked[$codeId])) {
            return null;
        }

        $code = $this->codesById[$codeId] ?? null;

        if ($code === null || $code->isExpired()) {
            return null;
        }

        // Mark as consumed atomically
        $this->consumed[$codeId] = true;

        return $code;
    }

    public function revoke(string $codeId): void
    {
        $this->revoked[$codeId] = true;

        $existing = $this->codesById[$codeId] ?? null;
        if ($existing !== null) {
            $this->codesById[$codeId] = new AuthorizationCode(
                id: $existing->id,
                clientId: $existing->clientId,
                subjectId: $existing->subjectId,
                redirectUri: $existing->redirectUri,
                scopes: $existing->scopes,
                codeChallenge: $existing->codeChallenge,
                codeChallengeMethod: $existing->codeChallengeMethod,
                expiresAt: $existing->expiresAt,
                issuedAt: $existing->issuedAt,
                revoked: true,
                codeValue: $existing->codeValue,
                nonce: $existing->nonce,
            );
        }
    }

    public function isRevoked(string $codeId): bool
    {
        return isset($this->revoked[$codeId]) || isset($this->consumed[$codeId]);
    }
}
