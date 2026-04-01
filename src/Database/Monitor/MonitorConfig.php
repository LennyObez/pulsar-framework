<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for SQL monitoring and logging.
 *
 * By default, raw query bindings are never logged. In production, only
 * a binding hash is recorded for correlation. Environment confirmation
 * is required before enabling raw binding logging.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MonitorConfig
{
    /**
     * @param list<string> $piiColumns Column names containing personally identifiable information
     */
    public function __construct(
        public int $slowQueryThresholdMs = 1000,
        public bool $logRawBindings = false,
        public bool $requireEnvironmentConfirmation = true,
        public array $piiColumns = [],
    ) {}

    /**
     * Build from a raw config array.
     *
     * @param array{
     *     slow_query_threshold_ms?: int,
     *     log_raw_bindings?: bool|int|string,
     *     require_environment_confirmation?: bool|int|string,
     *     pii_columns?: list<string>,
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
        );
    }
}
