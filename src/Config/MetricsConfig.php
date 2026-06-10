<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
     * @param array{
     *     enabled?: bool|int|string,
     *     exporters?: array{
     *         openmetrics?: array{enabled?: bool|int|string, endpoint?: string},
     *         prometheus?: array{enabled?: bool|int|string, endpoint?: string},
     *     },
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $exporters = $data['exporters'] ?? null;
        if (!is_array($exporters)) {
            $exporters = [];
        }
        $exporter = $exporters['openmetrics'] ?? $exporters['prometheus'] ?? null;
        if (!is_array($exporter)) {
            $exporter = [];
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            exporterEnabled: (bool) ($exporter['enabled'] ?? false),
            exporterEndpoint: Coerce::string($exporter['endpoint'] ?? null, '/metrics'),
        );
    }
}
