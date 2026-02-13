<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedConnection;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\DatabaseQueryPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use RuntimeException;

#[CoversClass(InstrumentedConnection::class)]
final class InstrumentedConnectionTest extends TestCase
{
    /** @var ConnectionInterface&Stub */
    private ConnectionInterface $inner;

    /** @var CorrelationContextProviderInterface&Stub */
    private CorrelationContextProviderInterface $contextProvider;

    /** @var list<ConsoleEvent> */
    private array $emittedEvents = [];

    protected function setUp(): void
    {
        $this->inner = $this->createStub(ConnectionInterface::class);
        $this->contextProvider = $this->createStub(CorrelationContextProviderInterface::class);
        $this->contextProvider->method('current')->willReturn(new CorrelationContext(requestId: 'req-1'));
        $this->emittedEvents = [];
    }

    private function makeConnection(
        bool $storeRawSql = false,
        EnvironmentMode $mode = EnvironmentMode::Local,
    ): InstrumentedConnection {
        return new InstrumentedConnection(
            inner: $this->inner,
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $ctx): void {
                $this->emittedEvents[] = $event;
            },
            storeRawSql: $storeRawSql,
            environmentMode: $mode,
        );
    }

    #[Test]
    public function queryEmitsDatabaseQueryEvent(): void
    {
        $expectedResult = new Result([new Row(['id' => 1])]);
        $this->inner->method('query')->willReturn($expectedResult);
        $this->inner->method('name')->willReturn('default');

        $conn = $this->makeConnection();
        $result = $conn->query('SELECT * FROM users WHERE id = 1');

        self::assertSame($expectedResult, $result);
        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(DatabaseQueryPayload::class, $this->emittedEvents[0]);

        $payload = $this->emittedEvents[0];
        self::assertSame('default', $payload->connectionName);
        self::assertSame('SELECT', $payload->queryType);
        self::assertGreaterThan(0.0, $payload->durationMs);
        self::assertSame(1, $payload->rowCount);
        self::assertNull($payload->sqlRaw);
    }

    #[Test]
    public function querySkipsEventWhenDisabled(): void
    {
        $expectedResult = new Result([]);
        $this->inner->method('query')->willReturn($expectedResult);

        $conn = $this->makeConnection();
        $conn->enabled = false;

        $result = $conn->query('SELECT 1');

        self::assertSame($expectedResult, $result);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function executeEmitsDatabaseQueryEvent(): void
    {
        $this->inner->method('execute')->willReturn(3);
        $this->inner->method('name')->willReturn('default');

        $conn = $this->makeConnection();
        $count = $conn->execute('UPDATE users SET active = 1');

        self::assertSame(3, $count);
        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(DatabaseQueryPayload::class, $this->emittedEvents[0]);

        $payload = $this->emittedEvents[0];
        self::assertSame('UPDATE', $payload->queryType);
        self::assertSame(3, $payload->rowCount);
    }

    #[Test]
    public function executeSkipsEventWhenDisabled(): void
    {
        $this->inner->method('execute')->willReturn(0);

        $conn = $this->makeConnection();
        $conn->enabled = false;

        $count = $conn->execute('DELETE FROM logs');

        self::assertSame(0, $count);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function storeRawSqlOnlyInLocalMode(): void
    {
        $expectedResult = new Result([]);
        $this->inner->method('query')->willReturn($expectedResult);
        $this->inner->method('name')->willReturn('default');

        $conn = $this->makeConnection(storeRawSql: true, mode: EnvironmentMode::Local);
        $conn->query("SELECT * FROM users WHERE name = 'Alice'");

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(DatabaseQueryPayload::class, $this->emittedEvents[0]);
        self::assertSame("SELECT * FROM users WHERE name = 'Alice'", $this->emittedEvents[0]->sqlRaw);
    }

    #[Test]
    public function storeRawSqlDisabledInProduction(): void
    {
        $expectedResult = new Result([]);
        $this->inner->method('query')->willReturn($expectedResult);
        $this->inner->method('name')->willReturn('default');

        $conn = $this->makeConnection(storeRawSql: true, mode: EnvironmentMode::Production);
        $conn->query("SELECT * FROM users WHERE name = 'Alice'");

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(DatabaseQueryPayload::class, $this->emittedEvents[0]);
        self::assertNull($this->emittedEvents[0]->sqlRaw);
    }

    #[Test]
    public function delegatesPrepareThroughToInner(): void
    {
        // Statement and Transaction are final, use real PdoConnection
        $realConn = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );
        $realConn->execute('CREATE TABLE t (id INTEGER)');

        $conn = new InstrumentedConnection(
            inner: $realConn,
            contextProvider: $this->contextProvider,
            emit: function (): void {},
        );

        $stmt = $conn->prepare('SELECT * FROM t WHERE id = :id');
        self::assertInstanceOf(Statement::class, $stmt);
    }

    #[Test]
    public function delegatesBeginTransactionThroughToInner(): void
    {
        $realConn = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $conn = new InstrumentedConnection(
            inner: $realConn,
            contextProvider: $this->contextProvider,
            emit: function (): void {},
        );

        $txn = $conn->beginTransaction();
        self::assertInstanceOf(Transaction::class, $txn);
        $txn->commit();
    }

    #[Test]
    public function delegatesTransactionThroughToInner(): void
    {
        $this->inner->method('transaction')->willReturn('result');

        $conn = $this->makeConnection();
        self::assertSame('result', $conn->transaction(fn() => 'result'));
    }

    #[Test]
    public function delegatesLastInsertIdThroughToInner(): void
    {
        $this->inner->method('lastInsertId')->willReturn('42');

        $conn = $this->makeConnection();
        self::assertSame('42', $conn->lastInsertId());
    }

    #[Test]
    public function delegatesDriverThroughToInner(): void
    {
        $this->inner->method('driver')->willReturn(Driver::SQLite);

        $conn = $this->makeConnection();
        self::assertSame(Driver::SQLite, $conn->driver());
    }

    #[Test]
    public function delegatesNameThroughToInner(): void
    {
        $this->inner->method('name')->willReturn('test_conn');

        $conn = $this->makeConnection();
        self::assertSame('test_conn', $conn->name());
    }

    #[Test]
    public function delegatesInTransactionThroughToInner(): void
    {
        $this->inner->method('inTransaction')->willReturn(true);

        $conn = $this->makeConnection();
        self::assertTrue($conn->inTransaction());
    }

    #[Test]
    public function delegatesDisconnectThroughToInner(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('disconnect');

        $conn = new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function (): void {},
        );

        $conn->disconnect();
    }

    #[Test]
    public function innerReturnsUnderlyingConnection(): void
    {
        $conn = $this->makeConnection();
        self::assertSame($this->inner, $conn->inner());
    }

    #[Test]
    public function emitExceptionSwallowed(): void
    {
        $expectedResult = new Result([]);
        $this->inner->method('query')->willReturn($expectedResult);
        $this->inner->method('name')->willReturn('default');

        $conn = new InstrumentedConnection(
            inner: $this->inner,
            contextProvider: $this->contextProvider,
            emit: function (): never {
                throw new RuntimeException('emit failed');
            },
        );

        $result = $conn->query('SELECT 1');
        self::assertSame($expectedResult, $result);
    }
}
