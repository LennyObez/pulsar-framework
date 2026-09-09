<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Routing\ReadWriteConfig;
use Pulsar\Database\Routing\ReadWriteRouter;
use Pulsar\Database\Routing\RoutingConnectionManager;
use Pulsar\Database\Routing\StickinessContext;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(RoutingConnectionManager::class)]
final class RoutingConnectionManagerTest extends TestCase
{
    private ConnectionManagerInterface&Stub $inner;
    private ReadWriteRouter $router;
    private StickinessContext $stickiness;
    private ReadWriteConfig $config;
    private ConnectionInterface&Stub $primaryConnection;
    private ConnectionInterface&Stub $replicaConnection;

    protected function setUp(): void
    {
        $this->primaryConnection = $this->createStub(ConnectionInterface::class);
        $this->replicaConnection = $this->createStub(ConnectionInterface::class);

        $this->inner = $this->createStub(ConnectionManagerInterface::class);
        $this->router = new ReadWriteRouter();
        $this->stickiness = new StickinessContext();

        $this->config = new ReadWriteConfig(
            readHosts: ['replica-1', 'replica-2'],
            writeHost: 'primary',
            stickyDuration: 'request',
            enabled: true,
        );
    }

    #[Test]
    public function selectUsesReadReplica(): void
    {
        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->replicaConnection, $connection);
    }

    #[Test]
    public function insertUsesPrimary(): void
    {
        $this->inner->method('connection')
            ->willReturn($this->primaryConnection);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $connection = $manager->connectionForQuery('INSERT INTO users (name) VALUES ("test")');

        self::assertSame($this->primaryConnection, $connection);
    }

    #[Test]
    public function afterWriteReadsPinnedToPrimary(): void
    {
        $this->inner->method('connection')
            ->willReturn($this->primaryConnection);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // First: write operation pins to primary
        $manager->connectionForQuery('INSERT INTO users (name) VALUES ("test")');

        // Second: read should be pinned to primary
        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->primaryConnection, $connection);
    }

    /**
     * Stickiness only: the transaction guarantee is proved by the transaction
     * tests below, which open one instead of standing in for it with a pin.
     */
    #[Test]
    public function stickyStateRoutesReadsToPrimary(): void
    {
        $this->inner->method('connection')
            ->willReturn($this->primaryConnection);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $this->stickiness->markWrite('request');

        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->primaryConnection, $connection);
    }

    // ---------------------------------------------------------------
    // Transaction awareness
    //
    // A transaction that spans two connections reads one snapshot and
    // writes against a connection that shares no transactional state
    // with it. The connection is therefore chosen when the transaction
    // opens and every later statement is answered with that one.
    // ---------------------------------------------------------------

    #[Test]
    public function connectionWithoutAStatementUsesPrimary(): void
    {
        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // Nothing says this connection will only be read through: the caller can
        // write with it or open a transaction on it before the manager is asked
        // anything again.
        self::assertSame($this->primaryConnection, $manager->connection());
    }

    #[Test]
    public function transactionOpenedOnTheRoutedConnectionKeepsEveryStatement(): void
    {
        $inTransaction = false;
        $primary = $this->transactionalConnection($inTransaction);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $primary],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // The caller takes a connection and opens a transaction on it.
        $transactional = $manager->connection();
        $inTransaction = true;

        // Both statements belong to that transaction, whatever the router would
        // have made of them on their own.
        $read = $manager->connectionForQuery('SELECT * FROM accounts WHERE id = 1');
        $write = $manager->connectionForQuery('UPDATE accounts SET balance = 0 WHERE id = 1');
        $resolved = $manager->connection();

        self::assertSame($transactional, $read);
        self::assertSame($transactional, $write);
        self::assertSame($transactional, $resolved);
    }

    #[Test]
    public function transactionOpenedWithASelectDoesNotStartOnAReplica(): void
    {
        $inTransaction = false;
        $primary = $this->transactionalConnection($inTransaction);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $primary],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // BEGIN is not a read, so it opens on the primary and pins the rest of
        // the transaction there — including the SELECT it starts with.
        $begin = $manager->connectionForQuery('BEGIN');
        $inTransaction = true;

        $first = $manager->connectionForQuery('SELECT balance FROM accounts WHERE id = 1');
        $then = $manager->connectionForQuery('UPDATE accounts SET balance = balance - 10 WHERE id = 1');

        self::assertSame($primary, $begin);
        self::assertSame($primary, $first);
        self::assertSame($primary, $then);
    }

    #[Test]
    public function replicaOverrideDoesNotSplitAnOpenTransaction(): void
    {
        $inTransaction = false;
        $primary = $this->transactionalConnection($inTransaction);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $primary],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $manager->connection();
        $inTransaction = true;

        $manager->useReplica();
        $duringTransaction = $manager->connectionForQuery('SELECT * FROM accounts');

        self::assertSame($primary, $duringTransaction);

        // The override was consumed by the transaction, not queued for later.
        $inTransaction = false;
        self::assertSame($primary, $manager->connection());
    }

    #[Test]
    public function namedConnectionIsNotAnsweredWithTheTransactionConnection(): void
    {
        $inTransaction = false;
        $primary = $this->transactionalConnection($inTransaction);
        $archive = $this->createStub(ConnectionInterface::class);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $primary],
                ['archive', $archive],
                ['replica-1', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $manager->connection();
        $inTransaction = true;

        // Asking for a host by name is an explicit choice; the routed
        // transaction must not hand back a different database.
        self::assertSame($archive, $manager->connection('archive'));
    }

    #[Test]
    public function committedTransactionReleasesThePin(): void
    {
        $inTransaction = false;
        $primary = $this->transactionalConnection($inTransaction);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $primary],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $manager->connection();
        $inTransaction = true;
        self::assertSame($primary, $manager->connectionForQuery('SELECT 1'));

        // Commit ends the transaction; stickiness still owns read-after-write,
        // and once that is reset reads route to a replica again.
        $inTransaction = false;
        $manager->resetRouting();

        self::assertSame($this->replicaConnection, $manager->connectionForQuery('SELECT 1'));
    }

    #[Test]
    public function transactionOpenedOnAReplicaKeepsEveryStatementOnThatReplica(): void
    {
        $inTransaction = false;
        $replica = $this->transactionalConnection($inTransaction);

        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $replica],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // A read routed to a replica, which the caller then wraps in a
        // transaction for a consistent snapshot.
        self::assertSame($replica, $manager->connectionForQuery('SELECT * FROM ledger'));
        $inTransaction = true;

        // Round-robin would move to replica-2; the open transaction outranks it.
        self::assertSame($replica, $manager->connectionForQuery('SELECT * FROM ledger'));
        self::assertSame($replica, $manager->connection());
    }

    /**
     * A connection whose transaction state follows the caller's flag, so a test
     * can open and close a transaction the way a caller does.
     */
    private function transactionalConnection(bool &$inTransaction): ConnectionInterface&Stub
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('inTransaction')
            ->willReturnCallback(static function () use (&$inTransaction): bool {
                return $inTransaction;
            });

        return $connection;
    }

    #[Test]
    public function usePrimaryOverrideSingleQuery(): void
    {
        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $manager->usePrimary();
        $first = $manager->connectionForQuery('SELECT * FROM users');

        // Override consumed -- next query routes normally
        $second = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->primaryConnection, $first);
        self::assertSame($this->replicaConnection, $second);
    }

    #[Test]
    public function useReplicaOverrideSingleQuery(): void
    {
        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $this->replicaConnection],
                ['replica-2', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // Pin to primary via a write
        $manager->connectionForQuery('INSERT INTO users (name) VALUES ("test")');

        // Override to force replica despite stickiness
        $manager->useReplica();
        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->replicaConnection, $connection);
    }

    #[Test]
    public function replicaOverrideEmitsAuditEvent(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                self::callback(static fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'system:db.routing'),
                'database.replica_override',
                'connection',
                ['reason' => 'manual_override'],
            );

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
            $auditLogger,
        );

        $manager->useReplica();
    }

    #[Test]
    public function multipleReplicasLoadBalanced(): void
    {
        $replica1 = $this->createStub(ConnectionInterface::class);
        $replica2 = $this->createStub(ConnectionInterface::class);

        $inner = $this->createStub(ConnectionManagerInterface::class);
        $inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $replica1],
                ['replica-2', $replica2],
            ]);

        $manager = new RoutingConnectionManager(
            $inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $first = $manager->connectionForQuery('SELECT 1');
        $second = $manager->connectionForQuery('SELECT 2');

        // Round-robin: first goes to replica-1, second to replica-2
        self::assertSame($replica1, $first);
        self::assertSame($replica2, $second);
    }

    #[Test]
    public function getDefaultConnectionNameDelegatesToInner(): void
    {
        $this->inner->method('getDefaultConnectionName')
            ->willReturn('primary');

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        self::assertSame('primary', $manager->getDefaultConnectionName());
    }

    #[Test]
    public function disconnectDelegatesToInner(): void
    {
        $inner = $this->createMock(ConnectionManagerInterface::class);
        $inner->expects(self::once())
            ->method('disconnect')
            ->with('primary');

        $manager = new RoutingConnectionManager(
            $inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        $manager->disconnect('primary');
    }

    #[Test]
    public function resetRoutingClearsAllState(): void
    {
        $this->inner->method('connection')
            ->willReturnMap([
                [null, $this->primaryConnection],
                ['replica-1', $this->replicaConnection],
            ]);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // Create state: pin to primary
        $this->router->pinToPrimary();
        $this->stickiness->markWrite();
        $manager->usePrimary();

        $manager->resetRouting();

        // After reset, reads route to replica again
        $connection = $manager->connectionForQuery('SELECT * FROM users');
        self::assertSame($this->replicaConnection, $connection);
    }

    #[Test]
    public function noReadHostsAlwaysUsesPrimary(): void
    {
        $config = new ReadWriteConfig(
            readHosts: [],
            writeHost: 'primary',
            stickyDuration: 'request',
            enabled: true,
        );

        $this->inner->method('connection')
            ->willReturn($this->primaryConnection);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $config,
        );

        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->primaryConnection, $connection);
    }
}
