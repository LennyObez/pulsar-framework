<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;

/**
 * Reports driver-specific DDL capabilities.
 *
 * Used by the admin UI and DdlCompiler to guard operations that
 * are unsupported on certain drivers (e.g., DROP COLUMN on older SQLite).
 * @api
 */
#[Api(since: '1.0.0')]
final class SchemaCapabilities
{
    private ?string $cachedSqliteVersion = null;

    private ?string $cachedServerVersion = null;

    public function __construct(
        private readonly Driver $driver,
        private readonly ?ConnectionInterface $connection = null,
        private readonly DriverVariant $variant = DriverVariant::Standard,
    ) {}

    /**
     * Create capabilities for a specific MySQL variant.
     */
    public static function forDriverVariant(
        Driver $driver,
        DriverVariant $variant,
        ?ConnectionInterface $connection = null,
    ): self {
        return new self($driver, $connection, $variant);
    }

    /**
     * Whether the driver supports ALTER TABLE ... DROP COLUMN.
     *
     * MySQL/MariaDB/PostgreSQL: always true.
     * SQLite: true only if runtime version >= 3.35.0. Defaults to false if no connection available.
     */
    public function supportsDropColumn(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => $this->sqliteVersionAtLeast(),
        };
    }

    /**
     * Whether a column the primary key names can be dropped in place.
     *
     * Not implied by {@see supportsDropColumn()}, which is why it is asked separately:
     * SQLite has supported `DROP COLUMN` since 3.35.0 and still refuses it for a column
     * participating in the primary key, at every version. Narrowing a key there means
     * rebuilding the table.
     */
    public function supportsDroppingKeyColumn(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether a primary key can be added to a table that already exists.
     *
     * SQLite has no `ALTER TABLE ... ADD PRIMARY KEY`. It is a syntax error rather than a
     * limitation that degrades, so a caller that tries it anyway fails outright instead of
     * falling back; giving an existing SQLite table a key means rebuilding it.
     */
    public function supportsAddPrimaryKey(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports ALTER TABLE ... ALTER COLUMN TYPE.
     */
    public function supportsAlterColumnType(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports FOREIGN KEY syntax in CREATE TABLE.
     */
    public function supportsForeignKeySyntax(): bool
    {
        return true;
    }

    /**
     * Whether foreign keys are enforced by default (without extra PRAGMAs).
     *
     * SQLite requires PRAGMA foreign_keys = ON (Pulsar enables this in PdoConnection bootstrap).
     */
    public function foreignKeysEnforcedByDefault(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether a schema change made inside a transaction is undone when that transaction
     * rolls back.
     *
     * The answer is measured per engine rather than reasoned about, because the reasoning
     * that used to sit here got SQLite backwards and five migrations then wrote their
     * idempotency arguments on top of it:
     *
     * - **PostgreSQL — true.** `CREATE TABLE`, `ALTER TABLE ... ADD COLUMN`, `CREATE INDEX`
     *   and `DROP TABLE` issued after `BEGIN` are all gone again after `ROLLBACK`, and a
     *   `DROP TABLE` that rolls back leaves the rows. The one statement that will not join a
     *   transaction is `CREATE INDEX CONCURRENTLY`, which raises 25001 inside a block.
     * - **SQLite — true, which is the correction.** Measured on 3.53.2 through PDO: the same
     *   four statements all roll back, and a DDL statement neither ends the transaction nor
     *   commits the rows written before it in the same one. SQLite keeps its catalogue in an
     *   ordinary table and journals it with everything else, which is why. `VACUUM` is the
     *   exception and refuses to start inside a transaction at all.
     * - **MySQL — false.** Measured on 8.0.46: `CREATE TABLE`, `ALTER TABLE` and
     *   `CREATE INDEX` each commit implicitly before and after themselves, so each lands the
     *   moment it runs, the enclosing transaction is over, and rows written before the DDL
     *   are committed with it. PDO notices — `inTransaction()` reads false straight after —
     *   and a later `rollBack()` raises rather than undoing anything. Temporary-table DDL is
     *   the documented exception and does not force a commit, but no caller here issues any.
     *
     * ## What this method is not
     *
     * It is not a driver oracle. The three answers happen to be distinct today, and a caller
     * that reads `supportsTransactionalDdl()` to work out which engine it is talking to gets
     * the right answer by coincidence and the wrong one the moment a capability moves —
     * which is exactly what this correction did to the one caller that tried it. Ask
     * {@see driver()}.
     *
     * It is also not what makes a migration atomic. {@see \Pulsar\Database\Migration\MigrationRunner}
     * never consults it: it wraps every `up()` and `down()` in a transaction
     * unconditionally, so on PostgreSQL and SQLite a migration that throws partway leaves no
     * trace either way. What no engine can offer is atomicity across the boundary the runner
     * itself opens — `recordMigration()` runs after that transaction has committed, so a
     * process dying in between leaves a schema change no ledger row mentions, on all three
     * engines alike.
     */
    public function supportsTransactionalDdl(): bool
    {
        return match ($this->driver) {
            Driver::PostgreSQL, Driver::SQLite => true,
            Driver::MySQL => false,
        };
    }

    /**
     * Whether the driver supports native ENUM column types.
     */
    public function supportsNativeEnum(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => true,
            Driver::PostgreSQL, Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports ALTER TABLE ... ADD FOREIGN KEY.
     */
    public function supportsAddForeignKey(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports ALTER TABLE ... DROP FOREIGN KEY.
     */
    public function supportsDropForeignKey(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports the UNSIGNED modifier on integer types.
     */
    public function supportsUnsigned(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => true,
            Driver::PostgreSQL, Driver::SQLite => false,
        };
    }

    /**
     * Whether the driver supports native JSON column type with indexing.
     *
     * MySQL 8.0+: native JSON with generated column indexes.
     * MariaDB: longtext alias for JSON (no native JSON indexing).
     * PostgreSQL: native JSONB with GIN indexes.
     * SQLite: stored as TEXT.
     */
    public function supportsNativeJson(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => $this->variant !== DriverVariant::MariaDb,
            Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether CHECK constraints are ENFORCED, rather than parsed and ignored.
     *
     * The distinction is the whole point of this method. MySQL before 8.0.16 accepts
     * CHECK in a CREATE TABLE and silently discards it, so a constraint the schema
     * appears to carry does not exist at runtime — the worst shape a data-integrity
     * guarantee can take, because the DDL succeeds.
     *
     * MySQL enforces from 8.0.16, MariaDB from 10.2.1. PostgreSQL always has.
     * SQLite has since 3.3.0 (2006), which predates any runtime this framework
     * supports, so it needs no probe.
     */
    public function supportsCheckConstraints(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => $this->serverVersionAtLeast('8.0.16', '10.2.1'),
            Driver::PostgreSQL, Driver::SQLite => true,
        };
    }

    /**
     * Whether the driver supports window functions (OVER, PARTITION BY).
     *
     * MySQL gained them in 8.0 and MariaDB in 10.2; 5.7 is still widely deployed and
     * rejects them outright.
     */
    public function supportsWindowFunctions(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => $this->serverVersionAtLeast('8.0.0', '10.2.0'),
            Driver::PostgreSQL => true,
            Driver::SQLite => $this->sqliteVersionAtLeast('3.25.0'),
        };
    }

    /**
     * Whether the driver supports Common Table Expressions (WITH ... AS).
     *
     * Same version boundary as window functions: MySQL 8.0, MariaDB 10.2.1.
     */
    public function supportsCte(): bool
    {
        return match ($this->driver) {
            Driver::MySQL => $this->serverVersionAtLeast('8.0.0', '10.2.1'),
            Driver::PostgreSQL => true,
            Driver::SQLite => $this->sqliteVersionAtLeast('3.8.3'),
        };
    }

    /**
     * Which engine these capabilities describe.
     *
     * Exposed so that a caller needing the engine's name asks for it, instead of
     * reconstructing it from a chain of capability answers. That reconstruction was real
     * code — the admin schema pages derived `mysql`/`pgsql`/`sqlite` from
     * {@see supportsNativeEnum()} and {@see supportsTransactionalDdl()} — and it broke the
     * moment SQLite's transactional-DDL answer was corrected to the truth, silently
     * relabelling every SQLite database as PostgreSQL. A capability answers what the engine
     * can do; only this answers which engine it is.
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * Get the driver variant (Standard, MariaDB, Percona).
     */
    public function driverVariant(): DriverVariant
    {
        return $this->variant;
    }

    /**
     * Export capabilities as an associative array for frontend consumption.
     *
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'supportsDropColumn' => $this->supportsDropColumn(),
            'supportsAlterColumnType' => $this->supportsAlterColumnType(),
            'supportsForeignKeys' => $this->supportsForeignKeySyntax(),
            'supportsTransactionalDdl' => $this->supportsTransactionalDdl(),
            'supportsNativeEnum' => $this->supportsNativeEnum(),
            'supportsAddForeignKey' => $this->supportsAddForeignKey(),
            'supportsDropForeignKey' => $this->supportsDropForeignKey(),
            'supportsUnsigned' => $this->supportsUnsigned(),
            'supportsNativeJson' => $this->supportsNativeJson(),
            'supportsCheckConstraints' => $this->supportsCheckConstraints(),
            'supportsWindowFunctions' => $this->supportsWindowFunctions(),
            'supportsCte' => $this->supportsCte(),
            'driverVariant' => $this->variant->value,
        ];
    }

    /**
     * Whether the MySQL-family server is at least the given version.
     *
     * MySQL and MariaDB share a driver but not a version line — MariaDB 10.2 is newer
     * than MySQL 8.0 — so each gets its own floor and the answer depends on which
     * server actually answered. The variant is read from the live `VERSION()` string
     * rather than from the one supplied at construction: that one is a caller's hint,
     * and a hint that is wrong here reports a capability the server does not have.
     *
     * With no connection the answer is false, matching {@see sqliteVersionAtLeast()}.
     * The asymmetry of being wrong decides it: a capability wrongly reported absent
     * costs a fallback path, while one wrongly reported present emits SQL the server
     * rejects — or, for CHECK constraints, silently discards.
     */
    private function serverVersionAtLeast(string $mysqlMinimum, string $mariaDbMinimum): bool
    {
        if ($this->connection === null) {
            return false;
        }

        if ($this->cachedServerVersion === null) {
            $result = $this->connection->query('SELECT VERSION() AS version');

            if ($result->rows === []) {
                return false;
            }

            $this->cachedServerVersion = $result->rows[0]->getString('version');
        }

        $minimum = DriverVariant::detect($this->cachedServerVersion) === DriverVariant::MariaDb
            ? $mariaDbMinimum
            : $mysqlMinimum;

        // "10.5.18-MariaDB" and "8.0.35-26-Percona Server" carry a suffix that
        // version_compare would weigh as a trailing string part; only the numeric head
        // is being compared here.
        $numeric = preg_match('/^\d+(\.\d+)*/', $this->cachedServerVersion, $matches) === 1
            ? $matches[0]
            : $this->cachedServerVersion;

        return version_compare($numeric, $minimum, '>=');
    }

    /**
     * Whether the SQLite runtime version is >= the given minimum.
     */
    private function sqliteVersionAtLeast(string $minimum = '3.35.0'): bool
    {
        if ($this->connection === null) {
            return false;
        }

        if ($this->cachedSqliteVersion === null) {
            $result = $this->connection->query('SELECT sqlite_version() AS version');

            if ($result->rows === []) {
                return false;
            }

            $this->cachedSqliteVersion = $result->rows[0]->getString('version');
        }

        return version_compare($this->cachedSqliteVersion, $minimum, '>=');
    }
}
