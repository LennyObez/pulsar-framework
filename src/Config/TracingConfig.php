<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_float;
use function is_int;
use function is_numeric;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for the tracing section of observability config.
 */
#[Api(since: '1.0.0')]
readonly class TracingConfig
{
    public function __construct(
        public bool $enabled = false,
        public float $samplingRate = 0.1,
    ) {}

    /**
     * Build from the raw tracing config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rate = $data['sampling_rate'] ?? 0.1;

        if (is_float($rate) || is_int($rate)) {
            $samplingRate = (float) $rate;
        } elseif (is_numeric($rate)) {
            $samplingRate = (float) $rate;
        } else {
            $samplingRate = 0.1;
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            samplingRate: $samplingRate,
        );
    }
}
