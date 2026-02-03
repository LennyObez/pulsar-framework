<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for session settings.
 *
 * Maps from the `session` key of `config/security.php`.
 */
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
    public static function fromArray(array $data, Environment $environment): self
    {
        $cookieName = $environment->get('SESSION_COOKIE_NAME')
            ?? (string) ($data['cookie_name'] ?? 'PULSAR_SESSION'); // @phpstan-ignore cast.string

        $lifetime = (int) ($data['lifetime'] ?? 7200); // @phpstan-ignore cast.int

        $cookieHttpOnly = (bool) ($data['cookie_httponly'] ?? true);
        $cookieSecure = (bool) ($data['cookie_secure'] ?? true);

        $cookieSameSite = (string) ($data['cookie_samesite'] ?? 'Strict'); // @phpstan-ignore cast.string

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
