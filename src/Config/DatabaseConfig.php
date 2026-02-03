<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Database\Exception\DatabaseException;

/**
 * Top-level typed configuration DTO for `config/database.php`.
 *
 * Composes per-connection DTOs and migration settings.
 */
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
    ) {}

    /**
     * Build from the raw database config array and environment.
     *
     * @param array<string, mixed> $data Raw array from config/database.php
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var string $defaultFromConfig */
        $defaultFromConfig = $data['default'] ?? 'mysql';
        $defaultConnection = $environment->get('DB_CONNECTION') ?? $defaultFromConfig;

        /** @var array<string, array<string, mixed>> $connectionsData */
        $connectionsData = $data['connections'] ?? [];

        $connections = [];
        foreach ($connectionsData as $name => $connData) {
            /** @var array<string, mixed> $connData */
            $connections[$name] = ConnectionConfig::fromArray($name, $connData, $environment);
        }

        /** @var array<string, mixed> $migrationsData */
        $migrationsData = $data['migrations'] ?? [];
        /** @var string $migrationsTable */
        $migrationsTable = $migrationsData['table'] ?? 'pulsar_migrations';

        /** @var string $migrationsPath */
        $migrationsPath = $migrationsData['path'] ?? 'database/migrations';

        return new self(
            defaultConnection: $defaultConnection,
            connections: $connections,
            migrationsTable: $migrationsTable,
            migrationsPath: $migrationsPath,
        );
    }

    /**
     * Get a connection config by name.
     *
     * @throws DatabaseException If the connection is not configured.
     */
    public function connection(string $name): ConnectionConfig
    {
        if (!isset($this->connections[$name])) {
            throw DatabaseException::connectionNotConfigured($name);
        }

        return $this->connections[$name];
    }
}
