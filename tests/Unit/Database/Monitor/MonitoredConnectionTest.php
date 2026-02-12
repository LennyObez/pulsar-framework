<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Monitor\ConnectionAuditorInterface;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Monitor\SlowQueryDetectorInterface;
use Pulsar\Database\Monitor\SqlLoggerInterface;
use Pulsar\Database\Result;

final class MonitoredConnectionTest extends TestCase
{
    private ConnectionInterface&MockObject $inner;
    private SqlLoggerInterface&MockObject $sqlLogger;
    private SlowQueryDetectorInterface&MockObject $slowDetector;
    private ConnectionAuditorInterface&MockObject $auditor;
    private MonitoredConnection $connection;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(ConnectionInterface::class);
        $this->inner->method('name')->willReturn('test');
        $this->inner->method('driver')->willReturn(Driver::SQLite);

        $this->sqlLogger = $this->createMock(SqlLoggerInterface::class);
        $this->slowDetector = $this->createMock(SlowQueryDetectorInterface::class);
        $this->auditor = $this->createMock(ConnectionAuditorInterface::class);

        $this->auditor->expects($this->once())
            ->method('logConnect')
            ->with('test', Driver::SQLite);

        $this->connection = new MonitoredConnection(
            $this->inner,
            $this->sqlLogger,
            $this->slowDetector,
            $this->auditor,
        );
    }

    public function test_query_logs_sql_and_checks_slow(): void
    {
        $result = Result::fromArrays([['id' => 1]]);
        $this->inner->method('query')->willReturn($result);

        $this->sqlLogger->expects($this->once())
            ->method('log')
            ->with(
                'SELECT * FROM users WHERE id = :id',
                ['id' => 1],
                $this->isType('float'),
                1,
            );

        $this->slowDetector->expects($this->once())
            ->method('check')
            ->with('SELECT * FROM users WHERE id = :id', $this->isType('float'));

        $returned = $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        $this->assertSame($result, $returned);
    }

    public function test_execute_logs_sql_and_checks_slow(): void
    {
        $this->inner->method('execute')->willReturn(3);

        $this->sqlLogger->expects($this->once())
            ->method('log')
            ->with(
                'DELETE FROM sessions WHERE expired = :expired',
                ['expired' => true],
                $this->isType('float'),
                3,
            );

        $this->slowDetector->expects($this->once())->method('check');

        $rowCount = $this->connection->execute(
            'DELETE FROM sessions WHERE expired = :expired',
            ['expired' => true],
        );

        $this->assertSame(3, $rowCount);
    }

    public function test_disconnect_logs_disconnection(): void
    {
        $this->auditor->expects($this->once())
            ->method('logDisconnect')
            ->with('test');

        $this->inner->expects($this->once())->method('disconnect');

        $this->connection->disconnect();
    }

    public function test_delegates_name(): void
    {
        $this->assertSame('test', $this->connection->name());
    }

    public function test_delegates_driver(): void
    {
        $this->assertSame(Driver::SQLite, $this->connection->driver());
    }

    public function test_delegates_in_transaction(): void
    {
        $this->inner->method('inTransaction')->willReturn(true);

        $this->assertTrue($this->connection->inTransaction());
    }
}
