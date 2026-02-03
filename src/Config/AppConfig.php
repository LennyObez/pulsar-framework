<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for `config/app.php`.
 *
 * Environment variables `APP_NAME`, `APP_ENV`, `APP_DEBUG` override file values.
 */
readonly class AppConfig
{
    public function __construct(
        public string $name,
        public EnvironmentMode $mode,
        public bool $debug,
        public string $timezone,
        public string $locale,
    ) {}

    /**
     * Build an AppConfig from a raw config array and environment.
     *
     * @param array<string, mixed> $data Raw array from config/app.php
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        // Resolve mode: env var overrides file value
        $envValue = $environment->get('APP_ENV');
        $modeString = $envValue ?? (string) ($data['env'] ?? 'local'); // @phpstan-ignore cast.string
        $mode = EnvironmentMode::tryFrom($modeString) ?? EnvironmentMode::Local;

        // Resolve name: env var overrides file value
        $name = $environment->get('APP_NAME') ?? (string) ($data['name'] ?? 'Pulsar'); // @phpstan-ignore cast.string

        // Resolve debug: env var overrides file value, which overrides mode default
        $debugEnv = $environment->get('APP_DEBUG');

        if ($debugEnv !== null) {
            $debug = self::parseBool($debugEnv);
        } elseif (isset($data['debug'])) {
            $debug = (bool) $data['debug'];
        } else {
            $debug = $mode->isDebugByDefault();
        }

        $timezone = (string) ($data['timezone'] ?? 'UTC'); // @phpstan-ignore cast.string
        $locale = (string) ($data['locale'] ?? 'en'); // @phpstan-ignore cast.string

        return new self(
            name: $name,
            mode: $mode,
            debug: $debug,
            timezone: $timezone,
            locale: $locale,
        );
    }

    private static function parseBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }
}
