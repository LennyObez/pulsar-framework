<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable access token value object.
 */
#[Api(since: '1.0.0')]
final readonly class AccessToken
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $subjectId,
        public array $scopes,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $issuedAt,
        public bool $revoked = false,
        public ?string $tokenValue = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->revoked && !$this->isExpired();
    }

    /**
     * Redact sensitive values for debug output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'clientId' => $this->clientId,
            'subjectId' => $this->subjectId,
            'scopes' => $this->scopes,
            'expiresAt' => $this->expiresAt->format('c'),
            'issuedAt' => $this->issuedAt->format('c'),
            'revoked' => $this->revoked,
            'tokenValue' => $this->tokenValue !== null ? '[REDACTED]' : null,
        ];
    }
}
