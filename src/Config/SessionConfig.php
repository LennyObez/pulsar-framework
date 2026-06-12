<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for session settings.
 *
 * Maps from the `session` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
readonly class SessionConfig
{
    /**
     * @param array{
     *     user_agent?: array{enabled?: bool, mode?: string},
     *     remote_address?: array{enabled?: bool, mode?: string, ipv4_mask?: int, ipv6_mask?: int},
     *     fingerprint?: array{enabled?: bool, attributes?: list<string>},
     * } $validators
     */
    public function __construct(
        public string $cookieName,
        public int $lifetime,
        public bool $cookieHttpOnly,
        public bool $cookieSecure,
        public string $cookieSameSite,
        public bool $regenerateOnPrivilegeChange,
        public string $handler = 'file',
        public bool $encryption = true,
        public array $validators = [],
        public int $maxConcurrentSessions = 3,
        public string $cookiePath = '/',
        public string $cookieDomain = '',
        public int $gcProbability = 1,
        public int $gcDivisor = 100,
        public string $savePath = '',
        public int $cookieMaxPayloadSize = 2048,
        public int $cookieReplayWindow = 86400,
        public int $idleTimeout = 900,
        public bool $cookieHostPrefix = false,
    ) {}

    /**
     * Get the effective cookie name, applying the `__Host-` prefix when enabled.
     *
     * The `__Host-` prefix instructs browsers to enforce: Secure flag, Path=/,
     * and no Domain attribute: preventing cookie tossing attacks from sibling
     * subdomains. Requires `cookieSecure=true`, `cookiePath='/'`, and
     * `cookieDomain=''` to be valid per the spec.
     */
    #[NoDiscard]
    public function effectiveCookieName(): string
    {
        if ($this->cookieHostPrefix) {
            return '__Host-' . $this->cookieName;
        }

        return $this->cookieName;
    }

    /**
     * Build from the raw session config array.
     *
     * @param array{
     *     cookie_name?: string,
     *     lifetime?: int,
     *     cookie_httponly?: bool|int|string,
     *     cookie_secure?: bool|int|string,
     *     cookie_samesite?: string,
     *     regenerate_on_privilege_change?: bool|int|string,
     *     handler?: string,
     *     encryption?: bool|int|string,
     *     validators?: array{
     *         user_agent?: array{enabled?: bool, mode?: string},
     *         remote_address?: array{enabled?: bool, mode?: string, ipv4_mask?: int, ipv6_mask?: int},
     *         fingerprint?: array{enabled?: bool, attributes?: list<string>},
     *     },
     *     max_concurrent_sessions?: int,
     *     cookie_path?: string,
     *     cookie_domain?: string,
     *     gc_probability?: int,
     *     gc_divisor?: int,
     *     save_path?: string,
     *     cookie_max_payload_size?: int,
     *     cookie_replay_window?: int,
     *     idle_timeout?: int,
     *     cookie_host_prefix?: bool|int|string,
     * } $data Raw `session` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        return new self(
            cookieName: $environment->get('SESSION_COOKIE_NAME') ?? $data['cookie_name'] ?? 'PULSAR_SESSION',
            lifetime: $data['lifetime'] ?? 7200,
            cookieHttpOnly: (bool) ($data['cookie_httponly'] ?? true),
            cookieSecure: (bool) ($data['cookie_secure'] ?? true),
            cookieSameSite: $data['cookie_samesite'] ?? 'Strict',
            regenerateOnPrivilegeChange: (bool) ($data['regenerate_on_privilege_change'] ?? true),
            handler: $data['handler'] ?? 'file',
            encryption: (bool) ($data['encryption'] ?? true),
            validators: $data['validators'] ?? [],
            maxConcurrentSessions: $data['max_concurrent_sessions'] ?? 3,
            cookiePath: $data['cookie_path'] ?? '/',
            cookieDomain: $data['cookie_domain'] ?? '',
            gcProbability: $data['gc_probability'] ?? 1,
            gcDivisor: $data['gc_divisor'] ?? 100,
            savePath: $data['save_path'] ?? '',
            cookieMaxPayloadSize: $data['cookie_max_payload_size'] ?? 2048,
            cookieReplayWindow: $data['cookie_replay_window'] ?? 86400,
            idleTimeout: $data['idle_timeout'] ?? 900,
            cookieHostPrefix: (bool) ($data['cookie_host_prefix'] ?? false),
        );
    }
}
