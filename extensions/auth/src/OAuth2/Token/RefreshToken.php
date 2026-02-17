<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable refresh token value object.
 *
 * Refresh tokens are bound to: client + subject + session.
 * Stored hashed in the repository, never in plaintext.
 * Rotation policy: one-time use, new refresh token issued on each use.
 * Replay detection: reuse of a rotated-out token revokes entire token family.
 */
#[Api(since: '1.0.0')]
final readonly class RefreshToken
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $subjectId,
        public string $sessionId,
        public string $familyId,
        public array $scopes,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $issuedAt,
        public bool $revoked = false,
        public bool $consumed = false,
        public ?string $tokenValue = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->revoked && !$this->consumed && !$this->isExpired();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'clientId' => $this->clientId,
            'subjectId' => $this->subjectId,
            'sessionId' => $this->sessionId,
            'familyId' => $this->familyId,
            'scopes' => $this->scopes,
            'expiresAt' => $this->expiresAt->format('c'),
            'issuedAt' => $this->issuedAt->format('c'),
            'revoked' => $this->revoked,
            'consumed' => $this->consumed,
            'tokenValue' => $this->tokenValue !== null ? '[REDACTED]' : null,
        ];
    }
}
