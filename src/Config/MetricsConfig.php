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
final readonly class MetricsConfig implements ReportsUnknownKeys
{
    /** Keys read from the `metrics` sub-array of config/observability.php. */
    private const array KNOWN_KEYS = ['enabled', 'exporters'];

    /** Exporter names this DTO understands; any other name is never read. */
    private const array KNOWN_EXPORTERS = ['openmetrics', 'prometheus'];

    /** Keys read from the selected exporter's own array. */
    private const array KNOWN_EXPORTER_KEYS = ['enabled', 'endpoint'];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `metrics` array that this
     *     DTO does not read, including an unrecognised exporter name and the selected
     *     exporter's own keys.
     */
    public function __construct(
        public bool $enabled = true,
        public bool $exporterEnabled = false,
        public string $exporterEndpoint = '/metrics',
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
        $selected = isset($exporters['openmetrics']) ? 'openmetrics' : 'prometheus';
        $exporter = $exporters['openmetrics'] ?? $exporters['prometheus'] ?? null;
        if (!is_array($exporter)) {
            $exporter = [];
        }

        // Three levels can hold a typo, and only the first was ever reported: the
        // section itself, the exporter NAME (`promethius` matches neither branch
        // above, so the block is skipped entirely and the exporter never starts),
        // and the selected exporter's own keys.
        $unknownKeys = [
            ...UnknownKeys::collect($data, self::KNOWN_KEYS),
            ...UnknownKeys::nestedKeys('exporters', UnknownKeys::collect($exporters, self::KNOWN_EXPORTERS)),
            ...UnknownKeys::nestedKeys(
                'exporters.' . $selected,
                UnknownKeys::collect($exporter, self::KNOWN_EXPORTER_KEYS),
            ),
        ];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            exporterEnabled: (bool) ($exporter['enabled'] ?? false),
            exporterEndpoint: Coerce::string($exporter['endpoint'] ?? null, '/metrics'),
            unknownKeys: $unknownKeys,
        );
    }
}
