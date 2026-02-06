<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Collector configuration for Studio event collection.
 */
#[Internal]
readonly class StudioCollectorConfig
{
    public function __construct(
        public bool $http = true,
        public bool $database = true,
        public bool $logs = true,
        public bool $exceptions = true,
        public bool $scheduler = true,
        public bool $featureFlags = true,
        public bool $queue = true,
        public bool $storeRawSql = false,
        public bool $redactTableNames = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var array<string, mixed> $httpData */
        $httpData = $data['http'] ?? [];

        /** @var array<string, mixed> $databaseData */
        $databaseData = $data['database'] ?? [];

        /** @var array<string, mixed> $logsData */
        $logsData = $data['logs'] ?? [];

        /** @var array<string, mixed> $exceptionsData */
        $exceptionsData = $data['exceptions'] ?? [];

        /** @var array<string, mixed> $schedulerData */
        $schedulerData = $data['scheduler'] ?? [];

        /** @var array<string, mixed> $featureFlagsData */
        $featureFlagsData = $data['feature_flags'] ?? [];

        /** @var array<string, mixed> $queueData */
        $queueData = $data['queue'] ?? [];

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
            storeRawSql: $storeRawSql,
            redactTableNames: (bool) ($databaseData['redact_table_names'] ?? false),
        );
    }
}
