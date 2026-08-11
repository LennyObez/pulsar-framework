<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Portable\InListBuilder;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\SqlIdentifier;

use function bin2hex;
use function count;
use function random_bytes;
use function sprintf;

/**
 * Executes the SQL this framework generates against the engines it claims to support.
 *
 * Every other test of the dialects compares a generated string to a string a developer
 * wrote. That proves the compiler is self-consistent; it cannot prove the engine accepts
 * the result, and the two are not the same claim. A dialect verified only by string
 * comparison is a dialect nobody has ever run.
 *
 * These tests close that gap. They take the compiler's own output and hand it to a real
 * server, so the assertion is made by the engine rather than by an expectation.
 *
 * SQLite runs everywhere. MySQL and PostgreSQL need a server — see
 * {@see DatabaseEngine} for how one is configured, and note that a server which is
 * configured but unreachable fails rather than skips.
 */
final class SchemaContractTest extends TestCase
{
    private ?ConnectionInterface $connection = null;

    private string $table = '';

    protected function tearDown(): void
    {
        if ($this->connection !== null && $this->table !== '') {
            $this->connection->execute(sprintf(
                'DROP TABLE IF EXISTS %s',
                SqlIdentifier::quote($this->table, $this->connection->driver()),
            ));
        }

        $this->connection = null;
        $this->table = '';
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function engines(): iterable
    {
        foreach (DatabaseEngine::all() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    /**
     * The generated CREATE TABLE is accepted, and the table it describes is usable.
     *
     * This is the single most valuable assertion in the suite for a framework claiming
     * more than one engine: it is the statement every application issues first, and the
     * one where dialects diverge most — auto-increment, type names, identifier quoting
     * and index syntax all appear in it at once.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theGeneratedCreateTableIsAcceptedByTheEngine(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $quoted = SqlIdentifier::quote($this->table, $driver);

        foreach ($this->createStatements($driver) as $sql) {
            $connection->execute($sql);
        }

        $connection->execute(
            sprintf('INSERT INTO %s (sku, label, quantity) VALUES (:sku, :label, :qty)', $quoted),
            ['sku' => 'a-1', 'label' => 'first', 'qty' => 3],
        );

        $rows = $connection->query(sprintf('SELECT sku, label, quantity FROM %s', $quoted))->rows;

        self::assertCount(1, $rows, 'the row inserted into the generated table was not read back');
        self::assertSame('a-1', $rows[0]->getString('sku'));
        self::assertSame(3, $rows[0]->getInt('quantity'));
    }

    /**
     * The auto-increment column actually increments.
     *
     * Worth its own test because the failure is silent: a CREATE TABLE that omits
     * AUTO_INCREMENT, SERIAL or AUTOINCREMENT is still valid SQL, so the table is created
     * and only the second insert reveals that the key never advanced.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theGeneratedPrimaryKeyIncrementsWithoutBeingSupplied(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $quoted = SqlIdentifier::quote($this->table, $driver);

        foreach ($this->createStatements($driver) as $sql) {
            $connection->execute($sql);
        }

        foreach (['a-1', 'a-2', 'a-3'] as $sku) {
            $connection->execute(
                sprintf('INSERT INTO %s (sku, label, quantity) VALUES (:sku, :label, 1)', $quoted),
                ['sku' => $sku, 'label' => 'x'],
            );
        }

        $ids = [];

        foreach ($connection->query(sprintf('SELECT id FROM %s ORDER BY id', $quoted))->rows as $row) {
            $ids[] = $row->getInt('id');
        }

        self::assertCount(3, $ids);
        self::assertSame([$ids[0], $ids[0] + 1, $ids[0] + 2], $ids, 'the primary key did not increment');
    }

    /**
     * The upsert dialect does what the name promises on the engine it was built for.
     *
     * MySQL writes ON DUPLICATE KEY UPDATE, PostgreSQL and SQLite ON CONFLICT DO UPDATE.
     * The three are not interchangeable, and a string comparison cannot tell whether the
     * conflict target was right — only a second insert on the same key can.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theGeneratedUpsertUpdatesInsteadOfDuplicating(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $quoted = SqlIdentifier::quote($this->table, $driver);

        foreach ($this->createStatements($driver) as $sql) {
            $connection->execute($sql);
        }

        $sql = UpsertBuilder::compile(
            $driver,
            $this->table,
            ['sku', 'label', 'quantity'],
            ['sku'],
            ['label', 'quantity'],
        );

        $connection->execute($sql, ['sku' => 'a-1', 'label' => 'first', 'quantity' => 1]);
        $connection->execute($sql, ['sku' => 'a-1', 'label' => 'second', 'quantity' => 9]);

        $rows = $connection->query(sprintf('SELECT sku, label, quantity FROM %s', $quoted))->rows;

        self::assertCount(1, $rows, 'the upsert inserted a duplicate rather than updating');
        self::assertSame('second', $rows[0]->getString('label'));
        self::assertSame(9, $rows[0]->getInt('quantity'));
    }

    /**
     * The portable IN-list executes and selects the right rows.
     *
     * PostgreSQL is given `= ANY(:p)` against an array parameter while MySQL and SQLite
     * get an expanded placeholder list — a difference no string assertion can validate,
     * because being well-formed and being correct are different properties.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theGeneratedInListSelectsExactlyTheNamedRows(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $quoted = SqlIdentifier::quote($this->table, $driver);

        foreach ($this->createStatements($driver) as $sql) {
            $connection->execute($sql);
        }

        foreach (['a-1', 'a-2', 'a-3'] as $index => $sku) {
            $connection->execute(
                sprintf('INSERT INTO %s (sku, label, quantity) VALUES (:sku, :label, :qty)', $quoted),
                ['sku' => $sku, 'label' => 'x', 'qty' => $index],
            );
        }

        $wanted = ['a-1', 'a-3'];

        $rows = $connection->query(
            sprintf(
                'SELECT sku FROM %s WHERE %s ORDER BY sku',
                $quoted,
                InListBuilder::compile($driver, SqlIdentifier::quote('sku', $driver), 'sku', count($wanted)),
            ),
            InListBuilder::expandParams($driver, 'sku', $wanted),
        )->rows;

        self::assertCount(2, $rows);
        self::assertSame('a-1', $rows[0]->getString('sku'));
        self::assertSame('a-3', $rows[1]->getString('sku'));
    }

    /**
     * Connect, or say precisely why not.
     *
     * An engine nobody configured is skipped with a reason the ledger records. An engine
     * that was configured and cannot be reached raises, because a broken environment must
     * not be able to impersonate an empty one.
     */
    private function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $this->connection = DatabaseEngine::connect($driver);

        // Kept short deliberately. Parallel workers need the suffix for isolation, but
        // PostgreSQL prefixes every index name with its table name, so a long table name
        // spends the 63-character budget twice over before the index name is even added.
        $this->table = 'pc_' . bin2hex(random_bytes(5));

        return $this->connection;
    }

    /**
     * The DDL under test, produced by the framework's own compiler rather than written
     * here — the point being to execute what the framework generates, not what a test
     * author believes it generates.
     *
     * @return list<string>
     */
    private function createStatements(Driver $driver): array
    {
        $blueprint = new Blueprint($this->table);
        $blueprint->id();
        $blueprint->string('sku', 64);
        $blueprint->string('label');
        $blueprint->integer('quantity');
        // Short on purpose. The compiler prefixes index names with the table name on
        // PostgreSQL and not on the others, so a name that fits everywhere else can pass
        // 63 characters there — see the note on the table name below.
        $blueprint->unique('sku', 'uq_sku');

        return new DdlCompiler($driver, new SchemaCapabilities($driver))
            ->compileCreate($blueprint->toDefinition());
    }
}
