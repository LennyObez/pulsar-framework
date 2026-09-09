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
 *
 * Two rules keep the routing safe for transactional work:
 *
 * 1. **A transaction never spans two connections.** Once the routed connection
 *    reports an open transaction, every later request for the routed connection
 *    — {@see connection()} with no name, {@see connectionForQuery()}, and any
 *    pending single-query override — is answered with that same connection until
 *    it commits or rolls back. Splitting one transaction across the primary and
 *    a replica would read the replica's stale snapshot and then write on a
 *    connection that shares no transactional state with it.
 * 2. **A connection handed out without a statement goes to the primary.**
 *    {@see connection()} is asked for a connection, not for a query: the caller
 *    may write with it or open a transaction on it, and the role has to be
 *    chosen before either is visible. Replicas are reached from the SQL-aware
 *    {@see connectionForQuery()} or by asking for one with {@see useReplica()}.
 * @api
 */
#[Api(since: '1.0.0')]
final class RoutingConnectionManager implements QueryRouterInterface
{
    private ?ConnectionRole $nextQueryOverride = null;
    private int $replicaIndex = 0;

    /**
     * The primary and the most recent replica handed out for the routed
     * (unnamed) default, kept so a connection that reports `inTransaction()` can
     * be recognised on the next request. The instances are the ones the inner
     * manager caches per host, so the pin follows the connection every caller
     * shares rather than a copy of it. The primary is checked first: it is where
     * writes live, and it stays recognisable even after a later read was routed
     * to a replica.
     */
    private ?ConnectionInterface $routedPrimary = null;

    private ?ConnectionInterface $routedReplica = null;

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
        $open = $this->openTransactionConnection($name);

        if ($open !== null) {
            // An override cannot be honoured without splitting the transaction,
            // so it is consumed here rather than left to fire after the commit.
            $this->nextQueryOverride = null;

            return $open;
        }

        $role = $this->resolveRole();

        if ($role === ConnectionRole::Read && $this->config->readHosts !== []) {
            return $this->readReplicaConnection($name);
        }

        return $this->primaryConnection($name);
    }

    /**
     * The connection a caller gets when there is no statement to classify: the one an
     * open transaction has claimed, or the primary.
     *
     * Unlike {@see connection()} it never consumes a pending single-query override. An
     * override belongs to the next STATEMENT, and asking a connection which driver it
     * speaks, which dialect to compose for it, or whether a transaction is open is not
     * a statement. {@see RoutingConnection} answers exactly those questions through
     * this method, so a `useReplica()` still applies to the query that follows them
     * rather than being eaten by a query builder asking for the dialect.
     */
    #[Override]
    public function routedConnection(): ConnectionInterface
    {
        return $this->openTransactionConnection() ?? $this->primaryConnection();
    }

    /**
     * Route a SQL query to the appropriate connection.
     *
     * Considers an open transaction first, then single-query overrides,
     * stickiness state, and the router's SQL classification. `BEGIN` (and every
     * other statement the router does not classify as a read) goes to the
     * primary and pins the rest of the transaction there, so the connection for
     * a transaction is chosen once, when it opens, rather than per statement.
     */
    #[Override]
    public function connectionForQuery(string $sql): ConnectionInterface
    {
        $open = $this->openTransactionConnection();

        if ($open !== null) {
            $this->nextQueryOverride = null;

            return $open;
        }

        if ($this->nextQueryOverride !== null) {
            $role = $this->nextQueryOverride;
            $this->nextQueryOverride = null;

            if ($role === ConnectionRole::Read && $this->config->readHosts !== []) {
                return $this->readReplicaConnection();
            }

            return $this->primaryConnection();
        }

        if ($this->stickiness->shouldUsePrimary()) {
            return $this->primaryConnection();
        }

        $role = $this->router->route($sql);

        if ($role === ConnectionRole::Write) {
            $this->stickiness->markWrite($this->config->stickyDuration);

            return $this->primaryConnection();
        }

        if ($this->config->readHosts !== []) {
            return $this->readReplicaConnection();
        }

        return $this->primaryConnection();
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
     *
     * The override never moves a statement out of an open transaction: while the
     * routed connection is inside one it is consumed and discarded, because
     * reading the replica's snapshot from inside a transaction on the primary is
     * the inconsistency this manager exists to prevent.
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
        $this->routedPrimary = null;
        $this->routedReplica = null;
    }

    #[Override]
    public function getDefaultConnectionName(): string
    {
        return $this->inner->getDefaultConnectionName();
    }

    #[Override]
    public function disconnect(?string $name = null): void
    {
        // A remembered connection may be the one being dropped, and a
        // disconnected handle reports no transaction even when the caller still
        // believes one is open. Forget both rather than pin to a dead handle.
        $this->routedPrimary = null;
        $this->routedReplica = null;

        $this->inner->disconnect($name);
    }

    /**
     * The connection an open transaction has claimed, or null when none is open.
     *
     * Only the routed (unnamed) default is pinned: asking for a connection by
     * name is an explicit request for that host, and answering it with the
     * transaction's connection would silently hand back the wrong database.
     */
    private function openTransactionConnection(?string $name = null): ?ConnectionInterface
    {
        if ($name !== null) {
            return null;
        }

        if ($this->routedPrimary?->inTransaction() === true) {
            return $this->routedPrimary;
        }

        if ($this->routedReplica?->inTransaction() === true) {
            return $this->routedReplica;
        }

        return null;
    }

    /**
     * Get the primary connection, remembering it when it answers the routed
     * default so a transaction opened on it is recognised on the next request.
     * A named connection is outside the routing this manager pins.
     */
    private function primaryConnection(?string $name = null): ConnectionInterface
    {
        $connection = $this->inner->connection($name);

        if ($name === null) {
            $this->routedPrimary = $connection;
        }

        return $connection;
    }

    /**
     * Resolve the connection role for a request that carries no statement.
     */
    private function resolveRole(): ConnectionRole
    {
        if ($this->nextQueryOverride !== null) {
            $role = $this->nextQueryOverride;
            $this->nextQueryOverride = null;

            return $role;
        }

        // No SQL, no role. A connection handed out here can be written through
        // or have a transaction opened on it before this manager is consulted
        // again, and by then the choice cannot be taken back — a transaction
        // that opens with a SELECT still commits writes. The primary is the only
        // answer that is right for every use the caller may make of it; a caller
        // that knows its statement is a read asks connectionForQuery() or
        // useReplica() for a replica.
        return ConnectionRole::Write;
    }

    /**
     * Get a read replica connection using round-robin selection, remembering it
     * when it answers the routed default so a transaction opened on it keeps
     * every later statement on the snapshot it started from.
     */
    private function readReplicaConnection(?string $name = null): ConnectionInterface
    {
        $hosts = $this->config->readHosts;
        $selectedHost = $hosts[$this->replicaIndex % count($hosts)];
        $this->replicaIndex++;

        // Use the host as the connection name for replica resolution
        $connection = $this->inner->connection($name ?? $selectedHost);

        if ($name === null) {
            $this->routedReplica = $connection;
        }

        return $connection;
    }
}
