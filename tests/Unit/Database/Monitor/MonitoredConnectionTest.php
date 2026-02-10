<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Monitor\ConnectionAuditorInterface;
use Pulsar\Database\Monitor\MonitoredConnection;
use Pulsar\Database\Monitor\SlowQueryDetectorInterface;
use Pulsar\Database\Monitor\SqlLoggerInterface;
use Pulsar\Database\Result;

#[CoversClass(MonitoredConnection::class)]
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

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function queryLogsSqlAndChecksSlow(): void
    {
        $result = Result::fromArrays([['id' => 1]]);
        $this->inner->method('query')->willReturn($result);

        $this->sqlLogger->expects($this->once())
            ->method('log')
            ->with(
                'SELECT * FROM users WHERE id = :id',
                ['id' => 1],
                $this->isFloat(),
                1,
            );

        $this->slowDetector->expects($this->once())
            ->method('check')
            ->with('SELECT * FROM users WHERE id = :id', $this->isFloat());

        $returned = $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        $this->assertSame($result, $returned);
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function executeLogsSqlAndChecksSlow(): void
    {
        $this->inner->method('execute')->willReturn(3);

        $this->sqlLogger->expects($this->once())
            ->method('log')
            ->with(
                'DELETE FROM sessions WHERE expired = :expired',
                ['expired' => true],
                $this->isFloat(),
                3,
            );

        $this->slowDetector->expects($this->once())->method('check');

        $rowCount = $this->connection->execute(
            'DELETE FROM sessions WHERE expired = :expired',
            ['expired' => true],
        );

        $this->assertSame(3, $rowCount);
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function disconnectLogsDisconnection(): void
    {
        $this->auditor->expects($this->once())
            ->method('logDisconnect')
            ->with('test');

        $this->inner->expects($this->once())->method('disconnect');

        $this->connection->disconnect();
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function delegatesName(): void
    {
        $this->assertSame('test', $this->connection->name());
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function delegatesDriver(): void
    {
        $this->assertSame(Driver::SQLite, $this->connection->driver());
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function delegatesInTransaction(): void
    {
        $this->inner->method('inTransaction')->willReturn(true);

        $this->assertTrue($this->connection->inTransaction());
    }
}
