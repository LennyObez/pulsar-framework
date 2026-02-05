<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for `config/app.php`.
 *
 * Environment variables `APP_NAME`, `APP_ENV`, `APP_DEBUG` override file values.
 */
#[Api(since: '1.0.0')]
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
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        // Resolve mode: env var overrides file value
        $envValue = $environment->get('APP_ENV');
        $rawEnv = $data['env'] ?? 'local';
        $modeString = $envValue ?? (is_string($rawEnv) ? $rawEnv : 'local');
        $mode = EnvironmentMode::tryFrom($modeString) ?? EnvironmentMode::Local;

        // Resolve name: env var overrides file value
        $rawName = $data['name'] ?? 'Pulsar';
        $name = $environment->get('APP_NAME') ?? (is_string($rawName) ? $rawName : 'Pulsar');

        // Resolve debug: env var overrides file value, which overrides mode default
        $debugEnv = $environment->get('APP_DEBUG');

        if ($debugEnv !== null) {
            $debug = self::parseBool($debugEnv);
        } elseif (isset($data['debug'])) {
            $debug = (bool) $data['debug'];
        } else {
            $debug = $mode->isDebugByDefault();
        }

        $rawTimezone = $data['timezone'] ?? 'UTC';
        $timezone = is_string($rawTimezone) ? $rawTimezone : 'UTC';
        $rawLocale = $data['locale'] ?? 'en';
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

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
