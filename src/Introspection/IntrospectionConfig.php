<?php

declare(strict_types=1);

namespace Pulsar\Introspection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

/**
 * Configuration DTO for the introspection subsystem.
 *
 * Introspection is enabled by default in non-production environments.
 * The `INTROSPECTION_ENABLED` environment variable takes precedence over
 * file-based configuration when set.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IntrospectionConfig implements ReportsUnknownKeys
{
    /** Keys read from config/introspection.php. */
    private const array KNOWN_KEYS = ['enabled'];

    /**
     * @param list<string> $unknownKeys Keys present in config/introspection.php that this
     *     DTO does not read.
     */
    public function __construct(
        public bool $enabled = true,
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
     * Build from raw config array, environment variables, and environment mode.
     *
     * Resolution order:
     *   1. `INTROSPECTION_ENABLED` env var (if set)
     *   2. `$data['enabled']` from config file
     *   3. Default: enabled in local/staging, disabled in production
     *
     * @param array{enabled?: bool|int|string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment, EnvironmentMode $mode): self
    {
        $defaultEnabled = $mode !== EnvironmentMode::Production;

        $envOverride = $environment->get('INTROSPECTION_ENABLED');
        $enabled = $envOverride !== null
            ? $envOverride === 'true'
            : (bool) ($data['enabled'] ?? $defaultEnabled);

        return new self(
            enabled: $enabled,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
