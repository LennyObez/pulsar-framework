<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\PdoConnection;

/**
 * A {@see ConnectionManagerInterface} decorator that honours an active failover.
 *
 * Failover is detected out of band by {@see \Pulsar\Console\Command\DbFailoverWatchCommand}
 * and the promoted endpoint is published to a shared {@see FailoverStateStore}.
 * This decorator reads that store when the default (primary) connection is
 * requested: if a failover is active it connects to the promoted endpoint;
 * otherwise it delegates to the inner manager unchanged. Reads routed to
 * replicas, and any explicitly-named connection, always delegate — failover
 * only overrides the primary.
 *
 * Crucially there is NO per-request health check: the store read is the only
 * overhead on the hot path, so a healthy primary pays nothing beyond a single
 * cache lookup, and that result is cached for the process.
 */
#[Internal]
final class FailoverConnectionManager implements ConnectionManagerInterface
{
    private ?ConnectionInterface $failoverConnection = null;

    private ?string $failoverConnectionEndpoint = null;

    public function __construct(
        private readonly ConnectionManagerInterface $inner,
        private readonly FailoverStateStore $store,
        private readonly DatabaseConfig $config,
    ) {}

    #[Override]
    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->config->defaultConnection;

        // Failover only overrides the default (primary) connection.
        if ($name !== $this->config->defaultConnection) {
            return $this->inner->connection($name);
        }

        $endpoint = $this->store->currentEndpoint();

        // No active failover: the inner manager's configured primary is authoritative.
        if ($endpoint === null) {
            return $this->inner->connection($name);
        }

        // Active failover: reuse the cached connection unless the endpoint moved.
        if ($this->failoverConnection !== null && $this->failoverConnectionEndpoint === $endpoint) {
            return $this->failoverConnection;
        }

        $base = $this->config->connections[$name] ?? null;

        // The default connection isn't configured locally — defer to the inner manager.
        if (!$base instanceof ConnectionConfig) {
            return $this->inner->connection($name);
        }

        $connection = PdoConnection::fromConfig($this->withHost($base, $endpoint));
        $this->failoverConnection = $connection;
        $this->failoverConnectionEndpoint = $endpoint;

        return $connection;
    }

    #[Override]
    public function getDefaultConnectionName(): string
    {
        return $this->inner->getDefaultConnectionName();
    }

    #[Override]
    public function disconnect(?string $name = null): void
    {
        if ($this->failoverConnection !== null
            && ($name === null || $name === $this->config->defaultConnection)
        ) {
            $this->failoverConnection->disconnect();
            $this->failoverConnection = null;
            $this->failoverConnectionEndpoint = null;
        }

        $this->inner->disconnect($name);
    }

    /**
     * Clone a connection config with the host pointed at the failover endpoint.
     */
    private function withHost(ConnectionConfig $base, string $host): ConnectionConfig
    {
        return new ConnectionConfig(
            name: $base->name,
            driver: $base->driver,
            host: $host,
            port: $base->port,
            database: $base->database,
            username: $base->username,
            password: $base->password,
            charset: $base->charset,
            collation: $base->collation,
            options: $base->options,
        );
    }
}
