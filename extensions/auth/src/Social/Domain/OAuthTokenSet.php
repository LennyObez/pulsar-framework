<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Domain;

use Pulsar\Api\Api;
use SensitiveParameter;

/**
 * Immutable token set returned by an OAuth2 token endpoint.
 *
 * All token material is marked as sensitive to prevent accidental
 * exposure in stack traces, var_dump output, and error reports.
 * The __debugInfo() method redacts every token field.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OAuthTokenSet
{
    /**
     * @param string $accessToken      Bearer access token
     * @param ?string $tokenType       Token type (typically "Bearer")
     * @param ?int $expiresIn          Lifetime in seconds from issuance
     * @param ?string $refreshToken    Refresh token for token renewal
     * @param ?string $idToken         OIDC ID token (JWT)
     * @param ?array<string, mixed> $unverifiedClaims  Raw decoded claims before signature verification
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public ?string $tokenType = null,
        public ?int $expiresIn = null,
        #[SensitiveParameter]
        public ?string $refreshToken = null,
        #[SensitiveParameter]
        public ?string $idToken = null,
        public ?array $unverifiedClaims = null,
    ) {}

    /**
     * Redact all token material from debug output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[REDACTED]',
            'tokenType' => $this->tokenType,
            'expiresIn' => $this->expiresIn,
            'refreshToken' => $this->refreshToken !== null ? '[REDACTED]' : null,
            'idToken' => $this->idToken !== null ? '[REDACTED]' : null,
            'unverifiedClaims' => $this->unverifiedClaims !== null ? '[REDACTED]' : null,
        ];
    }
}
