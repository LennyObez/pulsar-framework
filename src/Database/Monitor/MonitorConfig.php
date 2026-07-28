<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

/**
 * Configuration for SQL monitoring and logging.
 *
 * By default, raw query bindings are never logged. In production, only
 * a binding hash is recorded for correlation. Environment confirmation
 * is required before enabling raw binding logging.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MonitorConfig implements ReportsUnknownKeys
{
    /** Keys read from the `monitor` sub-array of config/database.php. */
    private const array KNOWN_KEYS = [
        'slow_query_threshold_ms', 'log_raw_bindings', 'require_environment_confirmation',
        'pii_columns', 'enabled',
    ];

    /**
     * @param list<string> $piiColumns Column names containing personally identifiable information
     * @param bool $enabled Whether to decorate the database connection with SQL logging, slow-query
     *        detection, and connection auditing. Off by default: monitoring adds per-query timing and
     *        log overhead, so it is opt-in for a performance-sensitive default.
     * @param list<string> $unknownKeys Keys present in the raw `monitor` array that this
     *     DTO does not read — a misspelled `pii_columns` leaves personal data unredacted
     *     in slow-query output, which is exactly the leak that list exists to prevent.
     */
    public function __construct(
        public int $slowQueryThresholdMs = 1000,
        public bool $logRawBindings = false,
        public bool $requireEnvironmentConfirmation = true,
        public array $piiColumns = [],
        public bool $enabled = false,
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
     * Build from a raw config array.
     *
     * @param array{
     *     slow_query_threshold_ms?: int,
     *     log_raw_bindings?: bool|int|string,
     *     require_environment_confirmation?: bool|int|string,
     *     pii_columns?: list<string>,
     *     enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            slowQueryThresholdMs: $data['slow_query_threshold_ms'] ?? 1000,
            logRawBindings: (bool) ($data['log_raw_bindings'] ?? false),
            requireEnvironmentConfirmation: (bool) ($data['require_environment_confirmation'] ?? true),
            piiColumns: $data['pii_columns'] ?? [],
            enabled: (bool) ($data['enabled'] ?? false),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
