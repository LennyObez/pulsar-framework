<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedConnection;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;
use RuntimeException;

#[CoversClass(InstrumentedConnection::class)]
final class InstrumentedConnectionTest extends TestCase
{
    private TraceContext $traceContext;

    protected function setUp(): void
    {
        $this->traceContext = TraceContext::create();
    }

    #[Test]
    public function queryDelegatesToInnerAndCreatesSpan(): void
    {
        $expectedResult = new Result([]);
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn($expectedResult);
        $inner->method('driver')->willReturn(Driver::PostgreSQL);
        $inner->method('name')->willReturn('default');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $result = $connection->query('SELECT * FROM users WHERE id = ?', [':id' => 1]);

        self::assertSame($expectedResult, $result);
        self::assertInstanceOf(Span::class, $bag->span);
        self::assertStringContainsString('SELECT', $bag->span->name);
    }

    #[Test]
    public function queryWhenDisabledSkipsInstrumentation(): void
    {
        $expectedResult = new Result([]);
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn($expectedResult);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        $result = $connection->query('SELECT 1');

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function queryExceptionSetsErrorStatusAndRethrows(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willThrowException(new RuntimeException('Connection lost'));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('primary');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Connection lost');

        try {
            $connection->query('SELECT * FROM broken');
        } finally {
            self::assertInstanceOf(Span::class, $bag->span);
        }
    }

    #[Test]
    public function executeDelegatesToInnerAndCreatesSpan(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('execute')->willReturn(3);
        $inner->method('driver')->willReturn(Driver::SQLite);
        $inner->method('name')->willReturn('local');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $affected = $connection->execute('DELETE FROM sessions WHERE expired = 1');

        self::assertSame(3, $affected);
        self::assertInstanceOf(Span::class, $bag->span);
        self::assertStringContainsString('DELETE', $bag->span->name);
    }

    #[Test]
    public function executeWhenDisabledSkipsInstrumentation(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('execute')->willReturn(1);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        self::assertSame(1, $connection->execute('INSERT INTO logs (msg) VALUES (?)'));
    }

    #[Test]
    public function executeExceptionSetsErrorStatusAndRethrows(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('execute')->willThrowException(new RuntimeException('Deadlock'));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('primary');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $this->expectException(RuntimeException::class);

        try {
            $connection->execute('UPDATE accounts SET balance = 0');
        } finally {
            self::assertInstanceOf(Span::class, $bag->span);
        }
    }

    #[Test]
    public function prepareDelegatesToInner(): void
    {
        $prepareCalled = false;
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('prepare')->willReturnCallback(static function () use (&$prepareCalled): never {
            $prepareCalled = true;
            // Statement is final and requires PDOStatement; verify delegation only
            throw new RuntimeException('prepare-delegation-verified');
        });

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        try {
            $connection->prepare('SELECT ?');
        } catch (RuntimeException) {
            // Expected
        }

        self::assertTrue($prepareCalled);
    }

    #[Test]
    public function beginTransactionDelegatesToInner(): void
    {
        $beginCalled = false;
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('beginTransaction')->willReturnCallback(static function () use (&$beginCalled): never {
            $beginCalled = true;
            // Transaction is final and requires PDO; verify delegation only
            throw new RuntimeException('begin-delegation-verified');
        });

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        try {
            $connection->beginTransaction();
        } catch (RuntimeException) {
            // Expected
        }

        self::assertTrue($beginCalled);
    }

    #[Test]
    public function transactionWhenEnabledWrapsCallbackWithSelf(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('transaction')->willReturnCallback(
            static function (callable $cb): mixed {
                return $cb(null);
            },
        );

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: true,
        );

        $callbackExecuted = false;
        $result = $connection->transaction(static function () use (&$callbackExecuted): string {
            $callbackExecuted = true;
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertTrue($callbackExecuted);
    }

    #[Test]
    public function transactionWhenDisabledDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('transaction')->willReturn('delegated');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        self::assertSame('delegated', $connection->transaction(static fn() => 'ignored'));
    }

    #[Test]
    public function lastInsertIdDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('lastInsertId')->willReturn('42');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame('42', $connection->lastInsertId());
    }

    #[Test]
    public function driverDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('driver')->willReturn(Driver::PostgreSQL);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame(Driver::PostgreSQL, $connection->driver());
    }

    #[Test]
    public function nameDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('name')->willReturn('replica');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertSame('replica', $connection->name());
    }

    #[Test]
    public function inTransactionDelegatesToInner(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('inTransaction')->willReturn(true);

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        self::assertTrue($connection->inTransaction());
    }

    #[Test]
    public function disconnectDelegatesToInner(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('disconnect');

        $processor = $this->createStub(SpanProcessorInterface::class);

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $connection->disconnect();
    }

    #[Test]
    public function statementExportNoneDoesNotAddDbStatement(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('test');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::None,
        );

        $connection->query('SELECT 1');

        self::assertInstanceOf(Span::class, $bag->span);
        $attrs = $bag->span->attributes();
        self::assertArrayNotHasKey('db.statement', $attrs);
        self::assertArrayNotHasKey('db.statement.hash', $attrs);
    }

    #[Test]
    public function statementExportHashAddsHashAttribute(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('test');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Hash,
        );

        $connection->query('SELECT * FROM users');

        self::assertInstanceOf(Span::class, $bag->span);
        $attrs = $bag->span->attributes();
        self::assertArrayHasKey('db.statement.hash', $attrs);
        self::assertArrayNotHasKey('db.statement', $attrs);
    }

    #[Test]
    public function statementExportFullAddsFullStatement(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('test');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Full,
            regulatedMode: false,
        );

        $connection->query('SELECT * FROM users');

        self::assertInstanceOf(Span::class, $bag->span);
        $attrs = $bag->span->attributes();
        self::assertArrayHasKey('db.statement', $attrs);
        self::assertSame('SELECT * FROM users', $attrs['db.statement']);
    }

    #[Test]
    public function statementExportFullInRegulatedModeFallsBackToHash(): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('test');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Full,
            regulatedMode: true,
        );

        $connection->query('SELECT * FROM sensitive_data');

        self::assertInstanceOf(Span::class, $bag->span);
        $attrs = $bag->span->attributes();
        self::assertArrayHasKey('db.statement.hash', $attrs);
        self::assertArrayNotHasKey('db.statement', $attrs);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operationExtractionProvider(): iterable
    {
        yield 'select' => ['SELECT * FROM users', 'SELECT'];
        yield 'insert' => ['INSERT INTO users (name) VALUES (?)', 'INSERT'];
        yield 'update' => ['UPDATE users SET name = ?', 'UPDATE'];
        yield 'delete' => ['DELETE FROM users WHERE id = ?', 'DELETE'];
        yield 'leading whitespace' => ['  SELECT 1', 'SELECT'];
    }

    #[Test]
    #[DataProvider('operationExtractionProvider')]
    public function queryExtractsCorrectDbOperation(string $sql, string $expectedOp): void
    {
        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('test');

        [$processor, $bag] = $this->createProcessorCapture();

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
        );

        $connection->query($sql);

        self::assertInstanceOf(Span::class, $bag->span);
        self::assertSame($expectedOp, $bag->span->attributes()['db.operation']);
    }

    /**
     * Creates a processor that captures the span passed to onEnd().
     *
     * @return array{SpanProcessorInterface, SpanCaptureBag}
     */
    private function createProcessorCapture(): array
    {
        $bag = new SpanCaptureBag();
        $processor = new class ($bag) implements SpanProcessorInterface {
            public function __construct(private readonly SpanCaptureBag $bag) {}

            public function onEnd(Span $span): void
            {
                $this->bag->span = $span;
            }
        };

        return [$processor, $bag];
    }
}

/**
 * Mutable container for a captured span, avoiding by-ref property issues.
 */
final class SpanCaptureBag
{
    public ?Span $span = null;
}
