<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

use function hrtime;

/**
 * Decorator that adds SQL logging, slow query detection, and connection
 * auditing to every query executed through the wrapped connection.
 *
 * All connection methods delegate to the wrapped connection while
 * transparently collecting timing data and routing it to the configured
 * monitoring components.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MonitoredConnection implements ConnectionInterface
{
    public function __construct(
        private ConnectionInterface $wrapped,
        private SqlLoggerInterface $sqlLogger,
        private SlowQueryDetectorInterface $slowQueryDetector,
        private ConnectionAuditorInterface $auditor,
    ) {
        $this->auditor->logConnect($this->wrapped->name(), $this->wrapped->driver());
    }

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        $start = hrtime(true);
        $result = $this->wrapped->query($sql, $bindings);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->sqlLogger->log($sql, $bindings, $durationMs, $result->rowCount);
        $this->slowQueryDetector->check($sql, $durationMs);

        return $result;
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        $start = hrtime(true);
        $rowCount = $this->wrapped->execute($sql, $bindings);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->sqlLogger->log($sql, $bindings, $durationMs, $rowCount);
        $this->slowQueryDetector->check($sql, $durationMs);

        return $rowCount;
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return $this->wrapped->prepare($sql);
    }

    #[Override]
    public function beginTransaction(): Transaction
    {
        return $this->wrapped->beginTransaction();
    }

    #[Override]
    public function transaction(callable $callback): mixed
    {
        return $this->wrapped->transaction($callback);
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->wrapped->lastInsertId();
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->wrapped->driver();
    }

    #[Override]
    public function variant(): DriverVariant
    {
        return $this->wrapped->variant();
    }

    #[Override]
    public function dialect(): DialectInterface
    {
        return $this->wrapped->dialect();
    }

    #[Override]
    public function name(): string
    {
        return $this->wrapped->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->wrapped->inTransaction();
    }

    #[Override]
    public function disconnect(): void
    {
        $this->auditor->logDisconnect($this->wrapped->name());
        $this->wrapped->disconnect();
    }
}
