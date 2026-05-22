<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

/**
 * Collector configuration for Studio event collection.
 */
#[Internal]
final readonly class StudioCollectorConfig
{
    public function __construct(
        public bool $http = true,
        public bool $database = true,
        public bool $logs = true,
        public bool $exceptions = true,
        public bool $scheduler = true,
        public bool $featureFlags = true,
        public bool $queue = true,
        public bool $benchmark = true,
        public bool $runtime = true,
        public bool $storeRawSql = false,
        public bool $redactTableNames = false,
    ) {}

    /**
     * @param array{
     *     http?: array{enabled?: bool|int|string},
     *     database?: array{enabled?: bool|int|string, store_raw_sql?: bool|int|string, redact_table_names?: bool|int|string},
     *     logs?: array{enabled?: bool|int|string},
     *     exceptions?: array{enabled?: bool|int|string},
     *     scheduler?: array{enabled?: bool|int|string},
     *     feature_flags?: array{enabled?: bool|int|string},
     *     queue?: array{enabled?: bool|int|string},
     *     benchmark?: array{enabled?: bool|int|string},
     *     runtime?: array{enabled?: bool|int|string},
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $httpData = $data['http'] ?? [];
        $databaseData = $data['database'] ?? [];
        $logsData = $data['logs'] ?? [];
        $exceptionsData = $data['exceptions'] ?? [];
        $schedulerData = $data['scheduler'] ?? [];
        $featureFlagsData = $data['feature_flags'] ?? [];
        $queueData = $data['queue'] ?? [];
        $benchmarkData = $data['benchmark'] ?? [];
        $runtimeData = $data['runtime'] ?? [];

        $storeRawSql = $environment->get('STUDIO_STORE_RAW_SQL') !== null
            ? $environment->get('STUDIO_STORE_RAW_SQL') === 'true'
            : (bool) ($databaseData['store_raw_sql'] ?? false);

        return new self(
            http: (bool) ($httpData['enabled'] ?? true),
            database: (bool) ($databaseData['enabled'] ?? true),
            logs: (bool) ($logsData['enabled'] ?? true),
            exceptions: (bool) ($exceptionsData['enabled'] ?? true),
            scheduler: (bool) ($schedulerData['enabled'] ?? true),
            featureFlags: (bool) ($featureFlagsData['enabled'] ?? true),
            queue: (bool) ($queueData['enabled'] ?? true),
            benchmark: (bool) ($benchmarkData['enabled'] ?? true),
            runtime: (bool) ($runtimeData['enabled'] ?? true),
            storeRawSql: $storeRawSql,
            redactTableNames: (bool) ($databaseData['redact_table_names'] ?? false),
        );
    }
}
