<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Override;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;

/**
 * Connection manager that routes queries between primary and read replicas.
 *
 * Wraps an underlying ConnectionManager and applies read/write routing
 * using the ReadWriteRouter. Supports single-query overrides, automatic
 * primary stickiness after writes, and audit logging of replica overrides
 * for regulated environments.
 */
#[Api(since: '1.0.0')]
final class RoutingConnectionManager implements ConnectionManagerInterface
{
    private ?ConnectionRole $nextQueryOverride = null;
    private int $replicaIndex = 0;

    public function __construct(
        private readonly ConnectionManagerInterface $inner,
        private readonly ReadWriteRouterInterface $router,
        private readonly StickinessContext $stickiness,
        private readonly ReadWriteConfig $config,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    #[Override]
    public function connection(?string $name = null): ConnectionInterface
    {
        $role = $this->resolveRole();

        if ($role === ConnectionRole::Read && $this->config->readHosts !== []) {
            return $this->readReplicaConnection($name);
        }

        return $this->inner->connection($name);
    }

    /**
     * Route a SQL query to the appropriate connection.
     *
     * Considers single-query overrides, stickiness state, transaction
     * context, and the router's SQL classification.
     */
    public function connectionForQuery(string $sql): ConnectionInterface
    {
        if ($this->nextQueryOverride !== null) {
            $role = $this->nextQueryOverride;
            $this->nextQueryOverride = null;

            if ($role === ConnectionRole::Read && $this->config->readHosts !== []) {
                return $this->readReplicaConnection();
            }

            return $this->inner->connection();
        }

        if ($this->stickiness->shouldUsePrimary()) {
            return $this->inner->connection();
        }

        $role = $this->router->route($sql);

        if ($role === ConnectionRole::Write) {
            $this->stickiness->markWrite($this->config->stickyDuration);

            return $this->inner->connection();
        }

        if ($this->config->readHosts !== []) {
            return $this->readReplicaConnection();
        }

        return $this->inner->connection();
    }

    /**
     * Force the next query to use the primary connection.
     */
    public function usePrimary(): void
    {
        $this->nextQueryOverride = ConnectionRole::Write;
    }

    /**
     * Force the next query to use a read replica.
     *
     * In regulated presets, this override is audit-logged because it
     * bypasses stickiness guarantees and may read stale data.
     */
    public function useReplica(): void
    {
        $this->nextQueryOverride = ConnectionRole::Read;

        $this->auditLogger?->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('db.routing'),
            action: 'database.replica_override',
            resource: 'connection',
            metadata: ['reason' => 'manual_override'],
        );
    }

    /**
     * Reset routing state for a new request cycle.
     */
    public function resetRouting(): void
    {
        $this->router->reset();
        $this->stickiness->reset();
        $this->nextQueryOverride = null;
        $this->replicaIndex = 0;
    }

    #[Override]
    public function getDefaultConnectionName(): string
    {
        return $this->inner->getDefaultConnectionName();
    }

    #[Override]
    public function disconnect(?string $name = null): void
    {
        $this->inner->disconnect($name);
    }

    /**
     * Resolve the connection role considering overrides and stickiness.
     */
    private function resolveRole(): ConnectionRole
    {
        if ($this->nextQueryOverride !== null) {
            $role = $this->nextQueryOverride;
            $this->nextQueryOverride = null;

            return $role;
        }

        if ($this->stickiness->shouldUsePrimary()) {
            return ConnectionRole::Write;
        }

        return ConnectionRole::Read;
    }

    /**
     * Get a read replica connection using round-robin selection.
     */
    private function readReplicaConnection(?string $name = null): ConnectionInterface
    {
        $hosts = $this->config->readHosts;
        $selectedHost = $hosts[$this->replicaIndex % count($hosts)];
        $this->replicaIndex++;

        // Use the host as the connection name for replica resolution
        return $this->inner->connection($name ?? $selectedHost);
    }
}
