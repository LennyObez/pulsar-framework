<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable access token value object.
 * @api
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

    /**
     * Token-intrinsic activeness check: not revoked and not expired.
     *
     * F385.15: this method intentionally does NOT inspect the issuing
     * client's active flag, the granted-scope set, or the subject's
     * account status. Those are external attributes the token cannot
     * reach without a circular dependency on the client / scope /
     * subject repositories. Callers that need a full RFC 7662
     * introspection-grade "active" decision must compose:
     *
     *   $token->isActive()
     *       && $clientRepository->findById($token->clientId)?->active === true
     *       && $scopeRepository->stillGranted($token->scopes, $token->clientId)
     *       && $subjectRepository->isAccountActive($token->subjectId)
     *
     * The OAuth2 introspection endpoint
     * (`OAuth2AuthorizationServer::processIntrospectionRequest()`)
     * is the canonical place to apply that composition.
     */
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
