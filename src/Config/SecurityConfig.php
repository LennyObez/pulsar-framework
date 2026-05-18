<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level typed configuration DTO for `config/security.php`.
 *
 * Composes sub-config DTOs for session, CSRF, security headers, and rate limiting.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityConfig
{
    public function __construct(
        public SessionConfig $session,
        public CsrfConfig $csrf,
        public SecurityHeadersConfig $headers,
        public RateLimitConfig $rateLimit,
        public ?AuthConfig $auth = null,
        public string $cipherSuite = 'sodium',
    ) {}

    /**
     * Build from the raw security config array and environment.
     *
     * @param array{
     *     session?: array<string, mixed>,
     *     csrf?: array<string, mixed>,
     *     headers?: array<string, mixed>,
     *     rate_limiting?: array<string, mixed>,
     *     auth?: array<string, mixed>|null,
     *     cipher_suite?: string,
     * } $data Raw array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var array<string, mixed> $sessionData */
        $sessionData = $data['session'] ?? [];
        /** @var array<string, mixed> $csrfData */
        $csrfData = $data['csrf'] ?? [];
        $headersData = $data['headers'] ?? [];
        /** @var array<string, mixed> $rateLimitData */
        $rateLimitData = $data['rate_limiting'] ?? [];
        $authData = $data['auth'] ?? null;

        return new self(
            session: SessionConfig::fromArray($sessionData, $environment),
            csrf: CsrfConfig::fromArray($csrfData),
            headers: SecurityHeadersConfig::fromArray($headersData),
            rateLimit: RateLimitConfig::fromArray($rateLimitData),
            auth: $authData !== null ? AuthConfig::fromArray($authData) : null,
            cipherSuite: $data['cipher_suite'] ?? 'sodium',
        );
    }
}
