<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\DatabaseQueryPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Throwable;

use function microtime;

/**
 * ConnectionInterface decorator that captures query events for Studio.
 *
 * Times query()/execute() calls, normalizes SQL, and emits DatabaseQueryPayload
 * events with correlation context. Bound values are never stored.
 */
#[Internal]
final class InstrumentedConnection implements ConnectionInterface, CollectorInterface
{
    public bool $enabled = true;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly CorrelationContextProviderInterface $contextProvider,
        private readonly Closure $emit,
        private readonly bool $storeRawSql = false,
        private readonly EnvironmentMode $environmentMode = EnvironmentMode::Local,
    ) {}

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        if (!$this->enabled) {
            return $this->inner->query($sql, $bindings);
        }

        $start = microtime(true);

        $result = $this->inner->query($sql, $bindings);

        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->emitQueryEvent($sql, $durationMs, $result->rowCount);

        return $result;
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        if (!$this->enabled) {
            return $this->inner->execute($sql, $bindings);
        }

        $start = microtime(true);

        $rowCount = $this->inner->execute($sql, $bindings);

        $durationMs = (microtime(true) - $start) * 1000.0;

        $this->emitQueryEvent($sql, $durationMs, $rowCount);

        return $rowCount;
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
        return $this->inner->transaction($callback);
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

    /**
     * Get the underlying connection.
     */
    public function inner(): ConnectionInterface
    {
        return $this->inner;
    }

    private function emitQueryEvent(string $sql, float $durationMs, ?int $rowCount): void
    {
        $normalized = SqlNormalizer::normalize($sql);
        $fingerprint = SqlNormalizer::fingerprint($sql);
        $queryType = SqlNormalizer::detectQueryType($sql);

        $sqlRaw = null;
        if ($this->storeRawSql && $this->environmentMode === EnvironmentMode::Local) {
            $sqlRaw = $sql;
        }

        $event = new DatabaseQueryPayload(
            sql: $normalized,
            sqlFingerprint: $fingerprint,
            connectionName: $this->inner->name(),
            durationMs: $durationMs,
            rowCount: $rowCount,
            queryType: $queryType,
            sqlRaw: $sqlRaw,
        );

        $context = $this->contextProvider->current();

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }
}
