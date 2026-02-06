<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
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

    public function test_select_uses_read_replica(): void
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

    public function test_insert_uses_primary(): void
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

    public function test_after_write_reads_pinned_to_primary(): void
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

    public function test_transaction_always_uses_primary(): void
    {
        $this->inner->method('connection')
            ->willReturn($this->primaryConnection);

        $manager = new RoutingConnectionManager(
            $this->inner,
            $this->router,
            $this->stickiness,
            $this->config,
        );

        // Simulate being inside a transaction by pinning via stickiness
        $this->stickiness->markWrite('request');

        $connection = $manager->connectionForQuery('SELECT * FROM users');

        self::assertSame($this->primaryConnection, $connection);
    }

    public function test_use_primary_override_single_query(): void
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

    public function test_use_replica_override_single_query(): void
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

    public function test_replica_override_emits_audit_event(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                null,
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

    public function test_multiple_replicas_load_balanced(): void
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

    public function test_get_default_connection_name_delegates_to_inner(): void
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

    public function test_disconnect_delegates_to_inner(): void
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

    public function test_reset_routing_clears_all_state(): void
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

    public function test_no_read_hosts_always_uses_primary(): void
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
