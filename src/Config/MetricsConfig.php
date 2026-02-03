<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for the metrics section of observability config.
 */
readonly class MetricsConfig
{
    public function __construct(
        public bool $enabled = true,
        public bool $prometheusEnabled = false,
        public string $prometheusEndpoint = '/metrics',
    ) {}

    /**
     * Build from the raw metrics config array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $exporters */
        $exporters = $data['exporters'] ?? [];
        /** @var array<string, mixed> $prometheus */
        $prometheus = $exporters['prometheus'] ?? [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            prometheusEnabled: (bool) ($prometheus['enabled'] ?? false),
            prometheusEndpoint: (string) ($prometheus['endpoint'] ?? '/metrics'), // @phpstan-ignore cast.string
        );
    }
}
