<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedConnection;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

use function hash;

#[CoversClass(InstrumentedConnection::class)]
final class InstrumentedConnectionTest extends TestCase
{
    private TraceContext $traceContext;

    protected function setUp(): void
    {
        $this->traceContext = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );
    }

    #[Test]
    public function queryCreatesSpanWithDbAttributes(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('main');

        $connection = new InstrumentedConnection($inner, $processor, $this->traceContext);
        $connection->query('SELECT * FROM users');

        self::assertNotNull($capturedSpan);
        self::assertSame('mysql', $capturedSpan->attributes()['db.system']);
        self::assertSame('main', $capturedSpan->attributes()['db.name']);
        self::assertSame('SELECT', $capturedSpan->attributes()['db.operation']);
        self::assertSame(SpanStatus::Ok, $capturedSpan->status);
    }

    #[Test]
    public function executeCreatesSpanForWriteOperations(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('execute')->willReturn(1);
        $inner->method('driver')->willReturn(Driver::PostgreSQL);
        $inner->method('name')->willReturn('default');

        $connection = new InstrumentedConnection($inner, $processor, $this->traceContext);
        $connection->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'John']);

        self::assertNotNull($capturedSpan);
        self::assertSame('INSERT', $capturedSpan->attributes()['db.operation']);
        self::assertSame(SpanStatus::Ok, $capturedSpan->status);
    }

    #[Test]
    public function disabledPassesThroughWithoutSpan(): void
    {
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::never())->method('onEnd');

        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('query')->willReturn(new Result([]));

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            enabled: false,
        );

        $connection->query('SELECT 1');
    }

    #[Test]
    public function errorSetsSpanStatusToError(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willThrowException(new RuntimeException('Connection lost'));
        $inner->method('driver')->willReturn(Driver::SQLite);
        $inner->method('name')->willReturn('test');

        $connection = new InstrumentedConnection($inner, $processor, $this->traceContext);

        try {
            $connection->query('SELECT 1');
        } catch (RuntimeException) {
            // Expected
        }

        self::assertNotNull($capturedSpan);
        self::assertSame(SpanStatus::Error, $capturedSpan->status);
        self::assertSame(RuntimeException::class, $capturedSpan->attributes()['exception.type']);
        self::assertSame('RuntimeException (code: 0)', $capturedSpan->attributes()['exception.message']);
    }

    #[Test]
    public function statementExportNoneDoesNotIncludeStatement(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('main');

        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::None,
        );

        $connection->query('SELECT secret FROM vault');

        self::assertNotNull($capturedSpan);
        self::assertArrayNotHasKey('db.statement', $capturedSpan->attributes());
        self::assertArrayNotHasKey('db.statement.hash', $capturedSpan->attributes());
    }

    #[Test]
    public function statementExportHashExportsHash(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('main');

        $sql = 'SELECT * FROM users';
        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Hash,
        );

        $connection->query($sql);

        self::assertNotNull($capturedSpan);
        self::assertSame(hash('sha256', $sql), $capturedSpan->attributes()['db.statement.hash']);
        self::assertArrayNotHasKey('db.statement', $capturedSpan->attributes());
    }

    #[Test]
    public function statementExportFullExportsStatement(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('main');

        $sql = 'SELECT * FROM users';
        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Full,
        );

        $connection->query($sql);

        self::assertNotNull($capturedSpan);
        self::assertSame($sql, $capturedSpan->attributes()['db.statement']);
    }

    #[Test]
    public function regulatedModeDowngradesFullToHash(): void
    {
        $capturedSpan = null;
        $processor = $this->createMock(SpanProcessorInterface::class);
        $processor->expects(self::once())
            ->method('onEnd')
            ->with(self::callback(static function (Span $span) use (&$capturedSpan): bool {
                $capturedSpan = $span;
                return true;
            }));

        $inner = $this->createStub(ConnectionInterface::class);
        $inner->method('query')->willReturn(new Result([]));
        $inner->method('driver')->willReturn(Driver::MySQL);
        $inner->method('name')->willReturn('main');

        $sql = 'SELECT * FROM patients';
        $connection = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $this->traceContext,
            statementExport: DbStatementExport::Full,
            regulatedMode: true,
        );

        $connection->query($sql);

        self::assertNotNull($capturedSpan);
        // Full should be downgraded to Hash in regulated mode
        self::assertSame(hash('sha256', $sql), $capturedSpan->attributes()['db.statement.hash']);
        self::assertArrayNotHasKey('db.statement', $capturedSpan->attributes());
    }
}
