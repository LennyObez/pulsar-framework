<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;

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
        'key_overrides', 'tokenization', 'waf', 'threat_detection',
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
     * Prefix a nested section's unknown keys with its config path, so
     * `session` + `driver` reads as `session.driver`. Sections that do not yet
     * report unknown keys contribute nothing.
     *
     * @return list<string>
     */
    private static function nested(string $prefix, ?object $child): array
    {
        if (!$child instanceof ReportsUnknownKeys) {
            return [];
        }

        return array_map(
            static fn(string $key): string => $prefix . '.' . $key,
            $child->unknownConfigKeys(),
        );
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

        $unknownKeys = [
            ...UnknownKeys::collect($data, self::KNOWN_KEYS),
            ...self::nested('session', $session),
            ...self::nested('csrf', $csrf),
            ...self::nested('headers', $headers),
            ...self::nested('rate_limiting', $rateLimit),
            ...self::nested('auth', $auth),
        ];

        return new self(
            session: $session,
            csrf: $csrf,
            headers: $headers,
            rateLimit: $rateLimit,
            auth: $auth,
            cipherSuite: $data['cipher_suite'] ?? 'sodium',
            unknownKeys: $unknownKeys,
        );
    }
}
