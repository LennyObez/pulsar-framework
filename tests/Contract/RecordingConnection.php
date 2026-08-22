<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use Override;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

/**
 * A real connection that also keeps the list of statements written through it.
 *
 * ## The one thing a migration can do that leaves no trace
 *
 * Every other claim in this batch of contracts is settled by looking at the database
 * afterwards: a table is there or it is not, a column has a type, an index has columns and
 * a predicate, a row survived a replay. One claim is not, and it is
 * `20260821000004`'s self-clearing rebuild guard.
 *
 * That guard makes the migration drop a stale pending index on the first pass over a legacy
 * table and never again. Delete it and the second pass drops the index it has already
 * repaired and immediately rebuilds it — same name, same columns, same predicate. The
 * database afterwards is byte-for-byte the database the guarded run leaves, so no
 * catalogue read, no row count and no query result differs. The only observable difference
 * is the statement that was issued, which is why this class exists: to make "and then
 * stops" a thing a test can watch happen rather than infer from nothing going wrong.
 *
 * ## Only writes are recorded
 *
 * `execute()` is recorded and `query()` is not, and the split is the assertion's. A
 * replayed migration over an already-correct schema is expected to *read* — every guard in
 * every one of these five migrations is a catalogue lookup — and expected to write nothing
 * at all. Recording both would make "the second pass changed nothing" indistinguishable
 * from "the second pass did nothing", and only the first is true or worth asserting.
 *
 * `prepare()` is not recorded either: nothing in these migrations goes through it, and a
 * statement whose execution happens later, elsewhere, is not something this can honestly
 * attribute to a pass.
 *
 * ## Everything else is the wrapped connection's answer
 *
 * Not a fake. The migration under test builds its own {@see \Pulsar\Database\Schema\
 * IndexOperations}, {@see \Pulsar\Database\Schema\TableIntrospector} and
 * {@see \Pulsar\Database\Schema\DdlCompiler} out of the connection it is handed, so this
 * has to be a connection to the real engine or the run being observed is not the run under
 * test. Driver, variant and dialect are forwarded for the same reason — a decorator that
 * answered any of them itself would be choosing the migration's engine-specific branches
 * for it.
 */
final class RecordingConnection implements ConnectionInterface
{
    /**
     * @var list<string>
     */
    private array $executed = [];

    public function __construct(
        private readonly ConnectionInterface $wrapped,
    ) {}

    /**
     * The statements written since the last drain, and start a fresh pass.
     *
     * Draining rather than reading is what lets one recorder span two `up()` calls and
     * still attribute each statement to the pass that issued it.
     *
     * @return list<string>
     */
    public function drainExecuted(): array
    {
        $executed = $this->executed;
        $this->executed = [];

        return $executed;
    }

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        return $this->wrapped->query($sql, $bindings);
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        $this->executed[] = $sql;

        return $this->wrapped->execute($sql, $bindings);
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
        $this->wrapped->disconnect();
    }
}
