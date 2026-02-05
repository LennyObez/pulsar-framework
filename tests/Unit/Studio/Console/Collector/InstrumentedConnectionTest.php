<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Studio\Console\Collector\InstrumentedConnection;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\DatabaseQueryPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;
use RuntimeException;

use function strlen;

#[CoversClass(InstrumentedConnection::class)]
final class InstrumentedConnectionTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    /** @psalm-suppress PropertyNotSetInConstructor */
    private FiberScopedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->emittedEvents = [];
        $this->contextProvider = new FiberScopedContextProvider();
    }

    #[Test]
    public function queryDelegatesToInnerConnection(): void
    {
        $expectedResult = Result::fromArrays([['id' => 1, 'name' => 'Test']]);

        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM users WHERE id = ?', ['id' => 1])
            ->willReturn($expectedResult);
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $result = $connection->query('SELECT * FROM users WHERE id = ?', ['id' => 1]);

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function queryEmitsDatabaseQueryEvent(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([['id' => 1]]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users WHERE id = 123');

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(DatabaseQueryPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    public function queryRecordsNormalizedSql(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users WHERE id = 123 AND name = \'John\'');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        // Normalized SQL should have literals replaced with ?
        self::assertSame('select * from users where id = ? and name = ?', $payload->sql);
    }

    #[Test]
    public function queryRecordsSqlFingerprint(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users WHERE id = 123');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(64, strlen($payload->sqlFingerprint)); // SHA-256 hex
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload->sqlFingerprint);
    }

    #[Test]
    public function queryRecordsConnectionName(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('analytics');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT COUNT(*) FROM events');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('analytics', $payload->connectionName);
    }

    #[Test]
    public function queryRecordsDuration(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturnCallback(function (): Result {
            usleep(5000); // 5ms
            return Result::fromArrays([]);
        });
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertGreaterThan(1.0, $payload->durationMs);
    }

    #[Test]
    public function queryRecordsRowCount(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(3, $payload->rowCount);
    }

    #[Test]
    public function queryRecordsQueryType(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('SELECT', $payload->queryType);
    }

    #[Test]
    public function queryDoesNotStoreRawSqlByDefault(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('SELECT * FROM users WHERE id = 123');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->sqlRaw);
    }

    #[Test]
    public function queryStoresRawSqlWhenEnabledInLocalMode(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
            storeRawSql: true,
            environmentMode: EnvironmentMode::Local,
        );

        $sql = 'SELECT * FROM users WHERE id = 123';
        $connection->query($sql);

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($sql, $payload->sqlRaw);
    }

    #[Test]
    public function queryDoesNotStoreRawSqlInProductionEvenWhenEnabled(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
            storeRawSql: true,
            environmentMode: EnvironmentMode::Production,
        );

        $connection->query('SELECT * FROM users WHERE id = 123');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->sqlRaw);
    }

    #[Test]
    public function executeDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('execute')
            ->with('UPDATE users SET name = ? WHERE id = ?', ['name' => 'Test', 'id' => 1])
            ->willReturn(1);
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $rowCount = $connection->execute('UPDATE users SET name = ? WHERE id = ?', ['name' => 'Test', 'id' => 1]);

        self::assertSame(1, $rowCount);
    }

    #[Test]
    public function executeEmitsDatabaseQueryEvent(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('execute')->willReturn(1);
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->execute('UPDATE users SET active = 1 WHERE id = 5');

        self::assertCount(1, $this->emittedEvents);
        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('UPDATE', $payload->queryType);
    }

    #[Test]
    public function executeRecordsAffectedRowCount(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('execute')->willReturn(42);
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->execute('DELETE FROM sessions WHERE expired_at < ?');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(42, $payload->rowCount);
    }

    // Note: Tests for prepare() and beginTransaction() delegation are omitted because
    // they return final classes (Statement, Transaction) which cannot be mocked.
    // The delegation behavior is implicitly tested through integration tests.

    #[Test]
    public function transactionDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(fn(callable $callback): mixed => $callback($inner));

        $connection = $this->createConnection($inner);

        $result = $connection->transaction(fn(ConnectionInterface $conn) => 'result');

        self::assertSame('result', $result);
    }

    #[Test]
    public function lastInsertIdDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('lastInsertId')
            ->willReturn('42');

        $connection = $this->createConnection($inner);

        self::assertSame('42', $connection->lastInsertId());
    }

    #[Test]
    public function driverDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('driver')
            ->willReturn(Driver::SQLite);

        $connection = $this->createConnection($inner);

        self::assertSame(Driver::SQLite, $connection->driver());
    }

    #[Test]
    public function nameDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('name')
            ->willReturn('custom_connection');

        $connection = $this->createConnection($inner);

        self::assertSame('custom_connection', $connection->name());
    }

    #[Test]
    public function inTransactionDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('inTransaction')
            ->willReturn(true);

        $connection = $this->createConnection($inner);

        self::assertTrue($connection->inTransaction());
    }

    #[Test]
    public function disconnectDelegatesToInnerConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())
            ->method('disconnect');

        $connection = $this->createConnection($inner);

        $connection->disconnect();
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);

        $connection = $this->createConnection($inner);

        self::assertTrue($connection->isEnabled());
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);

        $connection = $this->createConnection($inner);

        $connection->setEnabled(false);
        self::assertFalse($connection->isEnabled());

        $connection->setEnabled(true);
        self::assertTrue($connection->isEnabled());
    }

    #[Test]
    public function querySkipsInstrumentationWhenDisabled(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));

        $connection = $this->createConnection($inner);
        $connection->setEnabled(false);

        $connection->query('SELECT * FROM users');

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function executeSkipsInstrumentationWhenDisabled(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('execute')->willReturn(1);

        $connection = $this->createConnection($inner);
        $connection->setEnabled(false);

        $connection->execute('UPDATE users SET active = 1');

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function innerReturnsUnderlyingConnection(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);

        $connection = $this->createConnection($inner);

        self::assertSame($inner, $connection->inner());
    }

    #[Test]
    public function querySilentlySwallowsEmitExceptions(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([['id' => 1]]));
        $inner->method('name')->willReturn('default');

        $connection = new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function () {
                throw new RuntimeException('Emit failed');
            },
        );

        // Should not throw
        $result = $connection->query('SELECT * FROM users');

        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function executeSilentlySwallowsEmitExceptions(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('execute')->willReturn(5);
        $inner->method('name')->willReturn('default');

        $connection = new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function () {
                throw new RuntimeException('Emit failed');
            },
        );

        // Should not throw
        $rowCount = $connection->execute('DELETE FROM old_records');

        self::assertSame(5, $rowCount);
    }

    #[Test]
    public function queryIncludesCorrelationContext(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $context = new CorrelationContext(requestId: 'req-123', traceId: 'trace-456');
        $scope = $this->contextProvider->enter($context);

        try {
            $connection->query('SELECT * FROM users');

            $emittedContext = $this->emittedEvents[0]['context'];
            self::assertNotNull($emittedContext);
            self::assertSame('req-123', $emittedContext->requestId);
            self::assertSame('trace-456', $emittedContext->traceId);
        } finally {
            $scope->close();
        }
    }

    #[Test]
    public function queryHandlesInsertQueries(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('INSERT INTO users (name, email) VALUES (\'John\', \'john@example.com\')');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('INSERT', $payload->queryType);
        self::assertSame('insert into users (name, email) values (?, ?)', $payload->sql);
    }

    #[Test]
    public function queryHandlesDeleteQueries(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->method('query')->willReturn(Result::fromArrays([]));
        $inner->method('name')->willReturn('default');

        $connection = $this->createConnection($inner);

        $connection->query('DELETE FROM users WHERE id = 123');

        /** @var DatabaseQueryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('DELETE', $payload->queryType);
    }

    private function createConnection(ConnectionInterface $inner): InstrumentedConnection
    {
        return new InstrumentedConnection(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }
}
