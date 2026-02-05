<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_string;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for the metrics section of observability config.
 */
#[Api]
readonly class MetricsConfig
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
