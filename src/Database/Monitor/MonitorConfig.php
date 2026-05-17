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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $piiColumns */
        $piiColumns = $data['pii_columns'] ?? [];

        /** @var int $thresholdMs */
        $thresholdMs = $data['slow_query_threshold_ms'] ?? 1000;

        return new self(
            slowQueryThresholdMs: $thresholdMs,
            logRawBindings: (bool) ($data['log_raw_bindings'] ?? false),
            requireEnvironmentConfirmation: (bool) ($data['require_environment_confirmation'] ?? true),
            piiColumns: $piiColumns,
        );
    }
}
