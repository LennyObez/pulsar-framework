<?php

declare(strict_types=1);

namespace Pulsar\Auth\Token;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;

use function hash;
use function substr;

/**
 * Manages personal access token lifecycle.
 *
 * Handles creation, validation, and revocation of personal access tokens.
 * Tokens use a prefix format ("ptkn_" + 40 hex chars) for easy identification
 * in logs and credential scanners.
 *
 * Storage is delegated to PersonalAccessTokenStoreInterface, which can be
 * backed by a database, file, or in-memory store.
 * @api
 */
#[Api(since: '1.0.0')]
final class PersonalAccessTokenManager
{
    private const string TOKEN_PREFIX = 'ptkn_';
    private const int TOKEN_BYTES = 32;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly PersonalAccessTokenStoreInterface $store,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Create a new personal access token for a user.
     *
     * @param string $userId User ID
     * @param string $name Human-readable name for the token
     * @param list<string> $scopes Authorized scopes (empty = full access)
     * @param DateTimeImmutable|null $expiresAt When the token should expire
     */
    #[NoDiscard]
    public function createToken(
        string $userId,
        string $name,
        array $scopes = [],
        ?DateTimeImmutable $expiresAt = null,
    ): PersonalAccessTokenResult {
        $rawBytes = $this->randomizer->getBytes(self::TOKEN_BYTES);
        $plaintext = self::TOKEN_PREFIX . bin2hex($rawBytes);
        $tokenHash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 13); // "ptkn_" + 8 hex chars

        $token = new PersonalAccessToken(
            id: bin2hex($this->randomizer->getBytes(16)),
            userId: $userId,
            name: $name,
            tokenHash: $tokenHash,
            prefix: $prefix,
            scopes: $scopes,
            createdAt: new DateTimeImmutable(),
            expiresAt: $expiresAt,
        );

        $this->store->save($token);

        return new PersonalAccessTokenResult($token, $plaintext);
    }

    /**
     * Validate a plaintext token and return its record if valid.
     *
     * Returns null if the token is not found, expired, or invalid.
     */
    #[NoDiscard]
    public function validateToken(#[SensitiveParameter] string $plaintext): ?PersonalAccessToken
    {
        $tokenHash = hash('sha256', $plaintext);
        $token = $this->store->findByHash($tokenHash);

        if ($token === null) {
            return null;
        }

        if ($token->isExpired()) {
            return null;
        }

        $this->store->touchLastUsed($token->id);

        return $token;
    }

    /**
     * Revoke (delete) a token by its ID.
     */
    public function revokeToken(string $tokenId): bool
    {
        return $this->store->delete($tokenId);
    }

    /**
     * List all tokens for a user.
     *
     * @return list<PersonalAccessToken>
     */
    #[NoDiscard]
    public function tokensForUser(string $userId): array
    {
        return $this->store->findByUser($userId);
    }
}
