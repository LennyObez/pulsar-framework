<?php

declare(strict_types=1);

namespace Pulsar\Auth\Token;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Represents a personal access token issued to a user.
 *
 * Personal access tokens provide scoped API authentication without
 * the complexity of OAuth 2.0 flows. They are suitable for machine-
 * to-machine communication, CLI tools, and personal integrations.
 *
 * The plaintext token is only available immediately after creation
 * (via PersonalAccessTokenResult). Subsequent lookups return only
 * the hashed prefix for identification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PersonalAccessToken
{
    /**
     * @param string $id Unique token identifier (UUID or similar)
     * @param string $userId User who owns this token
     * @param string $name Human-readable label (e.g. "CI Deploy Key")
     * @param string $tokenHash SHA-256 hash of the full token for lookup
     * @param string $prefix First 8 chars of the token for identification (e.g. "ptkn_abc1")
     * @param list<string> $scopes Authorized scopes (empty = full access)
     * @param DateTimeImmutable $createdAt When the token was issued
     * @param DateTimeImmutable|null $expiresAt When the token expires (null = never)
     * @param DateTimeImmutable|null $lastUsedAt When the token was last used for auth
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $name,
        public string $tokenHash,
        public string $prefix,
        public array $scopes,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {}

    /**
     * Check if this token has expired.
     */
    #[NoDiscard]
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
    }

    /**
     * Check if this token has a specific scope.
     *
     * An empty scopes list means the token has full access.
     */
    #[NoDiscard]
    public function hasScope(string $scope): bool
    {
        if ($this->scopes === []) {
            return true; // No scope restrictions
        }

        return in_array($scope, $this->scopes, true);
    }

    /**
     * Check if this token can perform actions requiring all given scopes.
     *
     * @param list<string> $requiredScopes
     */
    #[NoDiscard]
    public function hasAllScopes(array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }

        return true;
    }
}
