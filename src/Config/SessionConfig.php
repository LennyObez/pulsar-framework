<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

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
     * @param list<string> $authenticatedMarkerKeys Session-data keys whose presence marks
     *     an authenticated session. The session subsystem reads these to force
     *     re-authentication on idle-timeout/validator failure (PCI-DSS 8.2.8) instead of
     *     silently regenerating. Defaults to the framework guard's `_pulsar_identity`
     *     (mirrors `Pulsar\Auth\Guard\SessionGuard`); custom guards should add their key.
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
        public array $authenticatedMarkerKeys = ['_pulsar_identity'],
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
     *     authenticated_marker_keys?: list<string>,
     * } $data Raw `session` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        // Secure-by-default in production, relaxed otherwise: an explicit
        // cookie_secure (config or SESSION_COOKIE_SECURE env) always wins, but
        // when unset the cookie is marked Secure only in production. This keeps
        // production cookies HTTPS-only while letting the session — and thus
        // CSRF-protected forms — work over plain http:// on the local dev
        // server, where a Secure cookie is never returned and every POST 403s.
        $appEnv = $environment->get('APP_ENV') ?? 'local';
        $secureEnv = $environment->get('SESSION_COOKIE_SECURE');
        $cookieSecure = match (true) {
            $secureEnv !== null => $secureEnv === 'true' || $secureEnv === '1',
            isset($data['cookie_secure']) => (bool) $data['cookie_secure'],
            default => $appEnv === 'production',
        };

        $config = new self(
            cookieName: $environment->get('SESSION_COOKIE_NAME') ?? $data['cookie_name'] ?? 'PULSAR_SESSION',
            lifetime: $data['lifetime'] ?? 7200,
            cookieHttpOnly: (bool) ($data['cookie_httponly'] ?? true),
            cookieSecure: $cookieSecure,
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
            authenticatedMarkerKeys: $data['authenticated_marker_keys'] ?? ['_pulsar_identity'],
        );

        // The `__Host-` cookie prefix is only honoured by browsers when the cookie
        // is Secure, has Path=/, and carries no Domain attribute. A mismatched
        // combination produces a cookie name that every browser silently rejects,
        // breaking the session (and thus authentication) with no error. Reject it
        // at config-build time so the misconfiguration surfaces immediately.
        if ($config->cookieHostPrefix
            && ($config->cookieSecure !== true || $config->cookiePath !== '/' || $config->cookieDomain !== '')
        ) {
            throw ConfigException::invalidValue(
                'security.session.cookie_host_prefix',
                "__Host- prefix requires cookie_secure=true, cookie_path='/', and empty cookie_domain",
            );
        }

        return $config;
    }
}
