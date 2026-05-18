<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for the tracing section of observability config.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TracingConfig
{
    public function __construct(
        public bool $enabled = false,
        public float $samplingRate = 0.1,
    ) {}

    /**
     * Build from the raw tracing config array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     sampling_rate?: float|int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            samplingRate: (float) ($data['sampling_rate'] ?? 0.1),
        );
    }
}
