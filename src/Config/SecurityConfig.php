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
final readonly class SecurityConfig implements ReportsUnknownKeys
{
    /**
     * Top-level keys of config/security.php. Includes sections this DTO does
     * NOT model — key_overrides, tokenization, waf, threat_detection are read
     * raw by their own wirings (e.g. ThreatDetectionWiring) — because
     * "known keys" means every key the section legitimately holds across all
     * consumers, not merely those this fromArray() reads.
     */
    private const array KNOWN_KEYS = [
        'session', 'csrf', 'headers', 'rate_limiting', 'auth', 'cipher_suite',
        'key_overrides', 'tokenization', 'waf', 'threat_detection', 'zero_trust',
    ];

    /**
     * @param list<string> $unknownKeys Unrecognized keys, own top-level plus any
     *     from nested sections (prefixed, e.g. `session.driver`). See {@see ReportsUnknownKeys}.
     */
    public function __construct(
        public SessionConfig $session,
        public CsrfConfig $csrf,
        public SecurityHeadersConfig $headers,
        public RateLimitConfig $rateLimit,
        public ?AuthConfig $auth = null,
        public string $cipherSuite = 'sodium',
        public ZeroTrustConfig $zeroTrust = new ZeroTrustConfig(),
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * A copy with a different session config. Used by compliance enforcement to
     * swap in a session config tightened to the active regulatory profile without
     * rebuilding the whole aggregate by hand.
     */
    #[NoDiscard]
    public function withSession(SessionConfig $session): self
    {
        return clone($this, ['session' => $session]);
    }

    /**
     * A copy with a different security-headers config. Used by compliance
     * enforcement to assert HTTPS (HSTS) when the active regulatory profile
     * requires encryption in transit.
     */
    #[NoDiscard]
    public function withHeaders(SecurityHeadersConfig $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }

    /**
     * A copy with a different auth config. Used by compliance enforcement to
     * enable MFA when the active regulatory profile requires it.
     */
    #[NoDiscard]
    public function withAuth(?AuthConfig $auth): self
    {
        return clone($this, ['auth' => $auth]);
    }

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
     *     zero_trust?: array<string, mixed>,
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

        $session = SessionConfig::fromArray($sessionData, $environment);
        $csrf = CsrfConfig::fromArray($csrfData);
        $headers = SecurityHeadersConfig::fromArray($headersData);
        $rateLimit = RateLimitConfig::fromArray($rateLimitData);
        $auth = $authData !== null ? AuthConfig::fromArray($authData) : null;
        /** @var array<string, mixed> $zeroTrustData */
        $zeroTrustData = $data['zero_trust'] ?? [];
        $zeroTrust = ZeroTrustConfig::fromArray($zeroTrustData);

        $unknownKeys = [
            ...UnknownKeys::collect($data, self::KNOWN_KEYS),
            ...UnknownKeys::nested('session', $session),
            ...UnknownKeys::nested('csrf', $csrf),
            ...UnknownKeys::nested('headers', $headers),
            ...UnknownKeys::nested('rate_limiting', $rateLimit),
            ...UnknownKeys::nested('auth', $auth),
            ...UnknownKeys::nested('zero_trust', $zeroTrust),
        ];

        return new self(
            session: $session,
            csrf: $csrf,
            headers: $headers,
            rateLimit: $rateLimit,
            auth: $auth,
            cipherSuite: $data['cipher_suite'] ?? 'sodium',
            zeroTrust: $zeroTrust,
            unknownKeys: $unknownKeys,
        );
    }
}
