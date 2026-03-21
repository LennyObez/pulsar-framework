<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for the metrics section of observability config.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MetricsConfig
{
    public function __construct(
        public bool $enabled = true,
        public bool $exporterEnabled = false,
        public string $exporterEndpoint = '/metrics',
    ) {}

    /**
     * Build from the raw metrics config array.
     *
     * Accepts both 'openmetrics' and legacy 'prometheus' exporter keys.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $exporters */
        $exporters = $data['exporters'] ?? [];
        /** @var array<string, mixed> $exporter */
        $exporter = $exporters['openmetrics'] ?? $exporters['prometheus'] ?? [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            exporterEnabled: (bool) ($exporter['enabled'] ?? false),
            exporterEndpoint: is_string($exporter['endpoint'] ?? null) ? $exporter['endpoint'] : '/metrics',
        );
    }
}
