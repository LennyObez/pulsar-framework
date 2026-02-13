<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Cache\QueryCacheConfig;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Failover\FailoverConfig;
use Pulsar\Database\Monitor\MonitorConfig;
use Pulsar\Database\Pool\PoolConfig;
use Pulsar\Database\Routing\ReadWriteConfig;

/**
 * Top-level typed configuration DTO for `config/database.php`.
 *
 * Composes per-connection DTOs and migration settings.
 */
#[Api(since: '1.0.0')]
readonly class DatabaseConfig
{
    /**
     * @param array<string, ConnectionConfig> $connections Keyed by connection name
     */
    public function __construct(
        public string $defaultConnection,
        public array $connections,
        public string $migrationsTable,
        public string $migrationsPath,
        public PoolConfig $pool = new PoolConfig(),
        public ReadWriteConfig $readWrite = new ReadWriteConfig(),
        public FailoverConfig $failover = new FailoverConfig(),
        public QueryCacheConfig $queryCache = new QueryCacheConfig(),
        public MonitorConfig $monitor = new MonitorConfig(),
    ) {}

    /**
     * Build from the raw database config array and environment.
     *
     * @param array<string, mixed> $data Raw array from config/database.php
     * @param string|null $basePath Project root for resolving relative SQLite paths
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment, ?string $basePath = null): self
    {
        /** @var string $defaultFromConfig */
        $defaultFromConfig = $data['default'] ?? 'mysql';
        $defaultConnection = $environment->get('DB_CONNECTION') ?? $defaultFromConfig;

        /** @var array<string, array<string, mixed>> $connectionsData */
        $connectionsData = $data['connections'] ?? [];

        $connections = [];
        foreach ($connectionsData as $name => $connData) {
            /** @var array<string, mixed> $connData */
            $connections[$name] = ConnectionConfig::fromArray($name, $connData, $environment, $basePath);
        }

        /** @var array<string, mixed> $migrationsData */
        $migrationsData = $data['migrations'] ?? [];
        /** @var string $migrationsTable */
        $migrationsTable = $migrationsData['table'] ?? 'pulsar_migrations';

        /** @var string $migrationsPath */
        $migrationsPath = $migrationsData['path'] ?? 'database/migrations';

        /** @var array<string, mixed> $poolData */
        $poolData = $data['pool'] ?? [];
        /** @var array<string, mixed> $readWriteData */
        $readWriteData = $data['read_write'] ?? [];
        /** @var array<string, mixed> $failoverData */
        $failoverData = $data['failover'] ?? [];
        /** @var array<string, mixed> $queryCacheData */
        $queryCacheData = $data['query_cache'] ?? [];
        /** @var array<string, mixed> $monitorData */
        $monitorData = $data['monitor'] ?? [];

        return new self(
            defaultConnection: $defaultConnection,
            connections: $connections,
            migrationsTable: $migrationsTable,
            migrationsPath: $migrationsPath,
            pool: $poolData !== [] ? PoolConfig::fromArray($poolData) : new PoolConfig(),
            readWrite: $readWriteData !== [] ? ReadWriteConfig::fromArray($readWriteData) : new ReadWriteConfig(),
            failover: $failoverData !== [] ? FailoverConfig::fromArray($failoverData) : new FailoverConfig(),
            queryCache: $queryCacheData !== [] ? QueryCacheConfig::fromArray($queryCacheData) : new QueryCacheConfig(),
            monitor: $monitorData !== [] ? MonitorConfig::fromArray($monitorData) : new MonitorConfig(),
        );
    }

    /**
     * Get a connection config by name.
     *
     * @throws DatabaseException If the connection is not configured.
     */
    public function connection(string $name): ConnectionConfig
    {
        return $this->connections[$name] ?? throw DatabaseException::connectionNotConfigured($name);
    }
}
