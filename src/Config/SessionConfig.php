<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for session settings.
 *
 * Maps from the `session` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SessionConfig
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
     * @param array<string, mixed> $data Raw `session` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $rawCookieName = $data['cookie_name'] ?? 'PULSAR_SESSION';
        $cookieName = $environment->get('SESSION_COOKIE_NAME')
            ?? (is_string($rawCookieName) ? $rawCookieName : 'PULSAR_SESSION');

        $rawLifetime = $data['lifetime'] ?? 7200;
        $lifetime = is_int($rawLifetime) ? $rawLifetime : (int) (is_numeric($rawLifetime) ? $rawLifetime : 7200);

        $cookieHttpOnly = (bool) ($data['cookie_httponly'] ?? true);
        $cookieSecure = (bool) ($data['cookie_secure'] ?? true);

        $rawCookieSameSite = $data['cookie_samesite'] ?? 'Strict';
        $cookieSameSite = is_string($rawCookieSameSite) ? $rawCookieSameSite : 'Strict';

        $regenerateOnPrivilegeChange = (bool) ($data['regenerate_on_privilege_change'] ?? true);

        $rawHandler = $data['handler'] ?? 'file';
        $handler = is_string($rawHandler) ? $rawHandler : 'file';

        $encryption = (bool) ($data['encryption'] ?? true);

        $rawValidators = $data['validators'] ?? [];
        $validatorsRaw = is_array($rawValidators) ? $rawValidators : [];
        /** @var array{user_agent?: array{enabled?: bool, mode?: string}, remote_address?: array{enabled?: bool, mode?: string, ipv4_mask?: int, ipv6_mask?: int}, fingerprint?: array{enabled?: bool, attributes?: list<string>}} $validators */
        $validators = $validatorsRaw;

        $rawMaxConcurrent = $data['max_concurrent_sessions'] ?? 3;
        $maxConcurrentSessions = is_int($rawMaxConcurrent) ? $rawMaxConcurrent : 3;

        $rawCookiePath = $data['cookie_path'] ?? '/';
        $cookiePath = is_string($rawCookiePath) ? $rawCookiePath : '/';

        $rawCookieDomain = $data['cookie_domain'] ?? '';
        $cookieDomain = is_string($rawCookieDomain) ? $rawCookieDomain : '';

        $rawGcProbability = $data['gc_probability'] ?? 1;
        $gcProbability = is_int($rawGcProbability) ? $rawGcProbability : 1;

        $rawGcDivisor = $data['gc_divisor'] ?? 100;
        $gcDivisor = is_int($rawGcDivisor) ? $rawGcDivisor : 100;

        $rawSavePath = $data['save_path'] ?? '';
        $savePath = is_string($rawSavePath) ? $rawSavePath : '';

        $rawCookieMaxPayload = $data['cookie_max_payload_size'] ?? 2048;
        $cookieMaxPayloadSize = is_int($rawCookieMaxPayload) ? $rawCookieMaxPayload : 2048;

        $rawCookieReplayWindow = $data['cookie_replay_window'] ?? 86400;
        $cookieReplayWindow = is_int($rawCookieReplayWindow) ? $rawCookieReplayWindow : 86400;

        $rawIdleTimeout = $data['idle_timeout'] ?? 900;
        $idleTimeout = is_int($rawIdleTimeout) ? $rawIdleTimeout : 900;

        $cookieHostPrefix = (bool) ($data['cookie_host_prefix'] ?? false);

        return new self(
            cookieName: $cookieName,
            lifetime: $lifetime,
            cookieHttpOnly: $cookieHttpOnly,
            cookieSecure: $cookieSecure,
            cookieSameSite: $cookieSameSite,
            regenerateOnPrivilegeChange: $regenerateOnPrivilegeChange,
            handler: $handler,
            encryption: $encryption,
            validators: $validators,
            maxConcurrentSessions: $maxConcurrentSessions,
            cookiePath: $cookiePath,
            cookieDomain: $cookieDomain,
            gcProbability: $gcProbability,
            gcDivisor: $gcDivisor,
            savePath: $savePath,
            cookieMaxPayloadSize: $cookieMaxPayloadSize,
            cookieReplayWindow: $cookieReplayWindow,
            idleTimeout: $idleTimeout,
            cookieHostPrefix: $cookieHostPrefix,
        );
    }
}
