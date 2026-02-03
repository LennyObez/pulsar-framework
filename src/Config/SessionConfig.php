<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;
use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for session settings.
 *
 * Maps from the `session` key of `config/security.php`.
 */
#[Api]
readonly class SessionConfig
{
    public function __construct(
        public string $cookieName,
        public int $lifetime,
        public bool $cookieHttpOnly,
        public bool $cookieSecure,
        public string $cookieSameSite,
        public bool $regenerateOnPrivilegeChange,
    ) {}

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

        return new self(
            cookieName: $cookieName,
            lifetime: $lifetime,
            cookieHttpOnly: $cookieHttpOnly,
            cookieSecure: $cookieSecure,
            cookieSameSite: $cookieSameSite,
            regenerateOnPrivilegeChange: $regenerateOnPrivilegeChange,
        );
    }
}
