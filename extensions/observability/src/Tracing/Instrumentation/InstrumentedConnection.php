<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Instrumentation;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Observability\Config\DbStatementExport;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Throwable;

use function hash;
use function preg_match;
use function strtoupper;
use function trim;

/**
 * Decorator that wraps a database connection with OpenTelemetry span instrumentation.
 *
 * Emits spans for query() and execute() calls with privacy-safe attributes.
 * Only exports db.system, db.name, and db.operation by default. Statement
 * export is controlled by DbStatementExport policy.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InstrumentedConnection implements ConnectionInterface
{
    public function __construct(
        private ConnectionInterface $inner,
        private SpanProcessorInterface $processor,
        private TraceContext $traceContext,
        private bool $enabled = true,
        private DbStatementExport $statementExport = DbStatementExport::None,
        private bool $regulatedMode = false,
    ) {}

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        if (!$this->enabled) {
            return $this->inner->query($sql, $bindings);
        }

        $span = $this->startSpan($sql);

        try {
            $result = $this->inner->query($sql, $bindings);
            $span->status = SpanStatus::Ok;

            return $result;
        } catch (Throwable $e) {
            $span->status = SpanStatus::Error;
            $span->setAttribute('exception.type', $e::class);
            $span->setAttribute('exception.message', $e::class . ' (code: ' . $e->getCode() . ')');

            throw $e;
        } finally {
            $span->end();
            $this->processor->onEnd($span);
        }
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        if (!$this->enabled) {
            return $this->inner->execute($sql, $bindings);
        }

        $span = $this->startSpan($sql);

        try {
            $result = $this->inner->execute($sql, $bindings);
            $span->status = SpanStatus::Ok;

            return $result;
        } catch (Throwable $e) {
            $span->status = SpanStatus::Error;
            $span->setAttribute('exception.type', $e::class);
            $span->setAttribute('exception.message', $e::class . ' (code: ' . $e->getCode() . ')');

            throw $e;
        } finally {
            $span->end();
            $this->processor->onEnd($span);
        }
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return $this->inner->prepare($sql);
    }

    #[Override]
    public function beginTransaction(): Transaction
    {
        return $this->inner->beginTransaction();
    }

    #[Override]
    public function transaction(callable $callback): mixed
    {
        if (!$this->enabled) {
            return $this->inner->transaction($callback);
        }

        return $this->inner->transaction(fn() => $callback($this));
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->inner->driver();
    }

    #[Override]
    public function variant(): \Pulsar\Database\DriverVariant
    {
        return $this->inner->variant();
    }

    #[Override]
    public function dialect(): \Pulsar\Database\Dialect\DialectInterface
    {
        return $this->inner->dialect();
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    #[Override]
    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    private function startSpan(string $sql): Span
    {
        $operation = self::extractOperation($sql);
        $childContext = $this->traceContext->createChild();

        $span = new Span(
            name: $operation . ' ' . $this->inner->name(),
            context: $childContext,
            parentSpanId: $this->traceContext->spanId,
        );

        $span->setAttribute('db.system', $this->inner->driver()->value);
        $span->setAttribute('db.name', $this->inner->name());
        $span->setAttribute('db.operation', $operation);
        $span->setAttribute('_span_kind', 3);

        $this->applyStatementExport($span, $sql);

        return $span;
    }

    private function applyStatementExport(Span $span, string $sql): void
    {
        $export = $this->statementExport;

        // Full export is force-disabled in regulated mode
        if ($export === DbStatementExport::Full && $this->regulatedMode) {
            $export = DbStatementExport::Hash;
        }

        match ($export) {
            DbStatementExport::None => null,
            DbStatementExport::Hash => $span->setAttribute('db.statement.hash', hash('sha256', $sql)),
            DbStatementExport::Full => $span->setAttribute('db.statement', $sql),
        };
    }

    private static function extractOperation(string $sql): string
    {
        $trimmed = trim($sql);

        if (preg_match('/^(\w+)/i', $trimmed, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }
}
