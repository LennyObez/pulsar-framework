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
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseConfig
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
     * @param array{
     *     default?: string,
     *     connections?: array<string, array<string, mixed>>,
     *     migrations?: array{table?: string, path?: string},
     *     pool?: array<string, mixed>,
     *     read_write?: array<string, mixed>,
     *     failover?: array<string, mixed>,
     *     query_cache?: array<string, mixed>,
     *     monitor?: array<string, mixed>,
     * } $data Raw array from config/database.php
     * @param string|null $basePath Project root for resolving relative SQLite paths
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment, ?string $basePath = null): self
    {
        $defaultConnection = $environment->get('DB_CONNECTION') ?? $data['default'] ?? 'mysql';

        $connections = [];
        foreach ($data['connections'] ?? [] as $name => $connData) {
            $connections[$name] = ConnectionConfig::fromArray($name, $connData, $environment, $basePath);
        }

        $migrationsData = $data['migrations'] ?? [];
        $migrationsTable = $migrationsData['table'] ?? 'pulsar_migrations';
        $migrationsPath = $migrationsData['path'] ?? 'database/migrations';

        $poolData = $data['pool'] ?? [];
        $readWriteData = $data['read_write'] ?? [];
        $failoverData = $data['failover'] ?? [];
        $queryCacheData = $data['query_cache'] ?? [];
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
