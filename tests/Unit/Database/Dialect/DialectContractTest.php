<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Dialect;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Dialect\Dialects;
use Pulsar\Database\Dialect\MariaDbDialect;
use Pulsar\Database\Dialect\MySqlDialect;
use Pulsar\Database\Dialect\PostgreSqlDialect;
use Pulsar\Database\Dialect\SqliteDialect;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\LockMode;

/**
 * The properties every dialect must hold, and the ones where they must differ.
 *
 * Written against the interface rather than against each class, so a fifth dialect is
 * covered by these the moment it exists — which is the point of having an interface at
 * all. Where engines genuinely disagree, the disagreement is asserted per engine, because
 * a contract that only checked the shared parts would pass a dialect that returns MySQL's
 * SQL under PostgreSQL's name.
 */
final class DialectContractTest extends TestCase
{
    /**
     * @return iterable<string, array{DialectInterface}>
     */
    public static function everyDialect(): iterable
    {
        foreach ([new MySqlDialect(), new MariaDbDialect(), new PostgreSqlDialect(), new SqliteDialect()] as $dialect) {
            yield $dialect->name() => [$dialect];
        }
    }

    /**
     * The mapping a new engine has to be added to, and the one a MariaDB server depends
     * on being right.
     */
    #[Test]
    public function theResolverPicksTheDialectForTheDriverAndVariant(): void
    {
        // The exact class, not instanceof: MariaDbDialect extends MySqlDialect, so
        // instanceof cannot tell the two apart — which is precisely the distinction this
        // test exists to hold.
        self::assertSame(MySqlDialect::class, Dialects::for(Driver::MySQL)::class);
        self::assertSame(PostgreSqlDialect::class, Dialects::for(Driver::PostgreSQL)::class);
        self::assertSame(SqliteDialect::class, Dialects::for(Driver::SQLite)::class);

        // The case that was unreachable before this layer existed: MariaDB arrives
        // through the MySQL driver, so resolving on the driver alone hands it MySQL's
        // dialect and silently costs it RETURNING and CREATE INDEX IF NOT EXISTS.
        $mariaDb = Dialects::for(Driver::MySQL, DriverVariant::MariaDb);

        self::assertSame(MariaDbDialect::class, $mariaDb::class);
        self::assertTrue($mariaDb->supportsReturning());
        self::assertTrue($mariaDb->supportsIndexIfNotExists());

        // Percona differs in server internals, not in accepted SQL.
        self::assertSame(
            MySqlDialect::class,
            Dialects::for(Driver::MySQL, DriverVariant::PerconaServer)::class,
        );
    }

    #[Test]
    #[DataProvider('everyDialect')]
    public function identifiersAreValidatedRatherThanEscaped(DialectInterface $dialect): void
    {
        self::assertStringContainsString('users', $dialect->quoteIdentifier('users'));

        // A name carrying a delimiter is refused on every engine. Escaping would accept
        // it; the identifiers reaching this method routinely come from a sort field or a
        // decoded pagination cursor, so refusal is the correct posture.
        $this->expectException(InvalidArgumentException::class);
        $dialect->quoteIdentifier('users"; DROP TABLE users');
    }

    #[Test]
    #[DataProvider('everyDialect')]
    public function noLimitAndNoOffsetCompilesToNothing(DialectInterface $dialect): void
    {
        self::assertSame('', $dialect->compileLimitOffset(null, null));
        self::assertSame('', $dialect->compileLimitOffset(null, 0));
    }

    #[Test]
    #[DataProvider('everyDialect')]
    public function lockModeNoneCompilesToNothingEverywhere(DialectInterface $dialect): void
    {
        self::assertSame('', $dialect->compileLock(LockMode::None));
    }

    /**
     * An engine that cannot lock rows must say so, not merely emit nothing.
     *
     * The two are indistinguishable at the call site: an empty clause reads exactly like
     * "no lock was requested". Only the capability flag lets a caller whose correctness
     * depends on the lock find out that it will not get one.
     */
    #[Test]
    #[DataProvider('everyDialect')]
    public function anEngineThatCannotLockRowsReportsSoRatherThanReturningSilence(DialectInterface $dialect): void
    {
        if ($dialect->supportsRowLocking()) {
            self::assertNotSame('', $dialect->compileLock(LockMode::ForUpdate));

            return;
        }

        self::assertSame('', $dialect->compileLock(LockMode::ForUpdate));
        self::assertSame('', $dialect->compileLock(LockMode::ForShare));
        self::assertSame(Driver::SQLite, $dialect->driver(), 'only SQLite is expected to lack row locking');
    }

    /**
     * The engine difference the migration corpus trips over most often.
     */
    #[Test]
    public function onlyMySqlLacksIfNotExistsOnCreateIndex(): void
    {
        self::assertFalse(new MySqlDialect()->supportsIndexIfNotExists());
        self::assertTrue(new MariaDbDialect()->supportsIndexIfNotExists());
        self::assertTrue(new PostgreSqlDialect()->supportsIndexIfNotExists());
        self::assertTrue(new SqliteDialect()->supportsIndexIfNotExists());
    }

    /**
     * The two statements every migration corpus repeats, and where they diverge.
     */
    #[Test]
    public function indexStatementsFollowEachEnginesGrammar(): void
    {
        $mysql = new MySqlDialect();
        $sqlite = new SqliteDialect();

        // MySQL drops through the owning table and accepts no IF EXISTS; the others drop
        // by name. A migration writing the standard form by hand fails on MySQL for a
        // reason that has nothing to do with the index.
        self::assertSame('DROP INDEX `idx_a` ON `t`', $mysql->compileDropIndex('idx_a', 't'));
        self::assertSame('DROP INDEX IF EXISTS "idx_a"', $sqlite->compileDropIndex('idx_a', 't'));

        // An IF NOT EXISTS that MySQL cannot express is dropped rather than refused, so
        // portable callers need not branch — and supportsIndexIfNotExists() is how they
        // learn the statement is not idempotent there.
        self::assertSame(
            'CREATE INDEX `idx_a` ON `t` (`a`, `b`)',
            $mysql->compileCreateIndex('idx_a', 't', ['a', 'b'], ifNotExists: true),
        );
        self::assertSame(
            'CREATE UNIQUE INDEX IF NOT EXISTS "idx_a" ON "t" ("a")',
            $sqlite->compileCreateIndex('idx_a', 't', ['a'], unique: true, ifNotExists: true),
        );
    }

    /**
     * Each engine keeps its indexes in its own catalogue, and none of the three names it
     * the same way. The query is compiled here so a caller that must establish existence
     * — which every caller on MySQL must, since neither `IF NOT EXISTS` nor `IF EXISTS`
     * is available there — does not go looking for the catalogue itself.
     */
    #[Test]
    public function eachEngineAnswersIndexExistenceFromItsOwnCatalogue(): void
    {
        self::assertStringContainsString(
            'information_schema.statistics',
            new MySqlDialect()->compileIndexExists(),
        );
        self::assertStringContainsString(
            'information_schema.statistics',
            new MariaDbDialect()->compileIndexExists(),
        );
        // `to_regclass` rather than `pg_indexes` filtered by `current_schemas()`: the
        // latter searches every schema on the path while the DDL it guards resolves a
        // bare name to exactly one, so on PostgreSQL's stock default path it can answer
        // for a different table entirely.
        $postgres = new PostgreSqlDialect()->compileIndexExists();
        self::assertStringContainsString('pg_index', $postgres);
        self::assertStringContainsString('to_regclass', $postgres);
        self::assertStringNotContainsString('current_schemas', $postgres);

        self::assertStringContainsString('sqlite_master', new SqliteDialect()->compileIndexExists());
    }

    /**
     * One binding contract across every engine, or the caller is back to branching.
     */
    #[Test]
    #[DataProvider('everyDialect')]
    public function indexExistenceTakesTheSameTwoBindingsEverywhere(DialectInterface $dialect): void
    {
        $sql = $dialect->compileIndexExists();

        self::assertStringContainsString(':table', $sql);
        self::assertStringContainsString(':index', $sql);
        self::assertStringContainsString('AS c', $sql, 'the count is read as the column "c"');
    }

    #[Test]
    #[DataProvider('everyDialect')]
    public function indexIdentifiersAreValidatedLikeAnyOther(DialectInterface $dialect): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dialect->compileCreateIndex('idx"; DROP TABLE t; --', 't', ['a']);
    }

    #[Test]
    public function booleanLiteralsFollowTheEnginesTypeSystem(): void
    {
        self::assertSame('1', new MySqlDialect()->compileBooleanLiteral(true));
        self::assertSame('0', new SqliteDialect()->compileBooleanLiteral(false));

        // PostgreSQL has a real boolean type and rejects 1 where one is expected.
        self::assertSame('TRUE', new PostgreSqlDialect()->compileBooleanLiteral(true));
        self::assertSame('FALSE', new PostgreSqlDialect()->compileBooleanLiteral(false));
    }

    #[Test]
    public function upsertUsesEachEnginesOwnConflictSyntax(): void
    {
        $insert = 'INSERT INTO t (sku, label) VALUES (:sku, :label)';

        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            new MySqlDialect()->compileUpsert($insert, ['sku'], ['label']),
        );

        $postgres = new PostgreSqlDialect()->compileUpsert($insert, ['sku'], ['label']);
        self::assertStringContainsString('ON CONFLICT ("sku") DO UPDATE SET', $postgres);
        self::assertStringContainsString('EXCLUDED.', $postgres);

        // SQLite spells the pseudo-table in lower case.
        $sqlite = new SqliteDialect()->compileUpsert($insert, ['sku'], ['label']);
        self::assertStringContainsString('ON CONFLICT ("sku") DO UPDATE SET', $sqlite);
        self::assertStringContainsString('excluded.', $sqlite);
    }

    #[Test]
    #[DataProvider('everyDialect')]
    public function everyDialectReportsItsOwnIdentity(DialectInterface $dialect): void
    {
        self::assertNotSame('', $dialect->name());

        // Round-trip: a dialect's own driver and variant resolve back to its own class,
        // so the resolver and the dialects cannot disagree about who serves whom.
        self::assertSame($dialect::class, Dialects::for($dialect->driver(), $dialect->variant())::class);
    }
}
