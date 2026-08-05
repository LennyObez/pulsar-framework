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
     * Whether the driver supports transactional DDL (atomic schema changes).
     */
    public function supportsTransactionalDdl(): bool
    {
        return match ($this->driver) {
            Driver::PostgreSQL => true,
            Driver::MySQL, Driver::SQLite => false,
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
