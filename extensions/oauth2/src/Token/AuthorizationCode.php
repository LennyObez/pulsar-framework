<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Token;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable authorization code value object.
 *
 * One-time use, short-lived (default 10 min).
 * Bound to: client + redirect_uri + PKCE verifier.
 * Stored hashed in the repository.
 */
#[Api(since: '1.0.0')]
final readonly class AuthorizationCode
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $subjectId,
        public string $redirectUri,
        public array $scopes,
        public string $codeChallenge,
        public string $codeChallengeMethod,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $issuedAt,
        public bool $revoked = false,
        public ?string $codeValue = null,
        public ?string $nonce = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
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
            'redirectUri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'codeChallengeMethod' => $this->codeChallengeMethod,
            'expiresAt' => $this->expiresAt->format('c'),
            'issuedAt' => $this->issuedAt->format('c'),
            'revoked' => $this->revoked,
            'codeValue' => $this->codeValue !== null ? '[REDACTED]' : null,
            'codeChallenge' => '[REDACTED]',
            'nonce' => $this->nonce !== null ? '[REDACTED]' : null,
        ];
    }
}
