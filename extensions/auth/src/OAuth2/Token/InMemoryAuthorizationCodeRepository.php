<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use SodiumException;

use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_generichash;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;

/**
 * In-memory authorization code repository.
 *
 * The raw code is dropped on persist: only a keyed BLAKE2b digest of it is
 * retained, as the lookup index. The index key is generated at construction
 * and dies with the instance, so a memory dump cannot be replayed against
 * another instance and the index alone cannot be walked with a pre-computed
 * code dictionary. Codes are one-time use with atomic consumption.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryAuthorizationCodeRepository implements AuthorizationCodeRepositoryInterface
{
    /** @var array<string, AuthorizationCode> Keyed by code ID */
    private array $codesById = [];

    /** @var array<string, string> BLAKE2b-keyed(codeValue) (hex) => code ID */
    private array $hashIndex = [];

    /** @var array<string, bool> Code IDs that have been revoked */
    private array $revoked = [];

    /** @var array<string, bool> Code IDs that have been consumed */
    private array $consumed = [];

    private readonly string $indexKey;

    public function __construct()
    {
        $this->indexKey = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
    }

    /**
     * @throws SodiumException
     */
    public function persist(AuthorizationCode $code): void
    {
        $this->codesById[$code->id] = $this->withoutPlaintext($code);

        if ($code->codeValue !== null) {
            $this->hashIndex[$this->hashCode($code->codeValue)] = $code->id;
        }
    }

    /**
     * @throws SodiumException
     */
    public function consume(string $codeValue): ?AuthorizationCode
    {
        $hash = $this->hashCode($codeValue);
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

    /**
     * Keyed BLAKE2b digest of a raw authorisation-code value.
     *
     * @throws SodiumException
     */
    private function hashCode(string $codeValue): string
    {
        return sodium_bin2hex(sodium_crypto_generichash($codeValue, $this->indexKey, 32));
    }

    /**
     * The stored copy carries everything but the credential itself; the raw
     * code survives only as the index digest.
     */
    private function withoutPlaintext(AuthorizationCode $code): AuthorizationCode
    {
        if ($code->codeValue === null) {
            return $code;
        }

        return new AuthorizationCode(
            id: $code->id,
            clientId: $code->clientId,
            subjectId: $code->subjectId,
            redirectUri: $code->redirectUri,
            scopes: $code->scopes,
            codeChallenge: $code->codeChallenge,
            codeChallengeMethod: $code->codeChallengeMethod,
            expiresAt: $code->expiresAt,
            issuedAt: $code->issuedAt,
            revoked: $code->revoked,
            codeValue: null,
            nonce: $code->nonce,
        );
    }
}
