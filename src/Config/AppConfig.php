<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/app.php`.
 *
 * Environment variables `APP_NAME`, `APP_ENV`, `APP_DEBUG` override file values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AppConfig
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
     * @param array{
     *     name?: string,
     *     env?: string,
     *     debug?: bool|int|string,
     *     timezone?: string,
     *     locale?: string,
     * } $data Raw array from config/app.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $modeString = $environment->get('APP_ENV') ?? $data['env'] ?? 'local';
        $mode = EnvironmentMode::tryFrom($modeString) ?? EnvironmentMode::Local;

        $name = $environment->get('APP_NAME') ?? $data['name'] ?? 'Pulsar';

        $debugEnv = $environment->get('APP_DEBUG');

        if ($debugEnv !== null) {
            $debug = self::parseBool($debugEnv);
        } elseif (isset($data['debug'])) {
            $debug = (bool) $data['debug'];
        } else {
            $debug = $mode->isDebugByDefault();
        }

        return new self(
            name: $name,
            mode: $mode,
            debug: $debug,
            timezone: $data['timezone'] ?? 'UTC',
            locale: $data['locale'] ?? 'en',
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
