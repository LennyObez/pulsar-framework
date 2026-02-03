<?php

declare(strict_types=1);

namespace Pulsar\Database;

use NoDiscard;
use Override;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\Exception\DatabaseException;

/**
 * Manages multiple database connections with lazy initialization and caching.
 */
final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    public function __construct(
        private readonly DatabaseConfig $config,
    ) {}

    /**
     * Create a ConnectionManager from a DatabaseConfig DTO.
     */
    #[NoDiscard]
    public static function fromConfig(DatabaseConfig $config): self
    {
        return new self($config);
    }

    #[Override]
    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->config->defaultConnection;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (!isset($this->config->connections[$name])) {
            throw DatabaseException::connectionNotConfigured($name);
        }

        $connectionConfig = $this->config->connections[$name];
        $connection = PdoConnection::fromConfig($connectionConfig);
        $this->connections[$name] = $connection;

        return $connection;
    }

    #[Override]
    public function getDefaultConnectionName(): string
    {
        return $this->config->defaultConnection;
    }

    #[Override]
    public function disconnect(?string $name = null): void
    {
        if ($name !== null) {
            if (isset($this->connections[$name])) {
                $this->connections[$name]->disconnect();
                unset($this->connections[$name]);
            }
            return;
        }

        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }
}
