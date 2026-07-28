<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for the tracing section of observability config.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TracingConfig implements ReportsUnknownKeys
{
    /** Keys read from the `tracing` sub-array of config/observability.php. */
    private const array KNOWN_KEYS = ['enabled', 'sampling_rate'];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `tracing` array that this
     *     DTO does not read — a misspelled `sampling_rate` silently restores the 0.1
     *     default, so a deployment meant to trace everything traces a tenth of it.
     */
    public function __construct(
        public bool $enabled = false,
        public float $samplingRate = 0.1,
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
            samplingRate: Coerce::float($data['sampling_rate'] ?? null, 0.1),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
