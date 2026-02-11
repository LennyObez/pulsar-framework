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
 */
#[Api(since: '1.0.0')]
final class SchemaCapabilities
{
    private ?string $cachedSqliteVersion = null;

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
     * Whether CHECK constraints are enforced (not just parsed and ignored).
     *
     * MariaDB 10.2+ enforces CHECK constraints; MySQL 8.0.16+ does too.
     * SQLite has enforced CHECK constraints.
     * PostgreSQL has always enforced them.
     */
    public function supportsCheckConstraints(): bool
    {
        return true;
    }

    /**
     * Whether the driver supports window functions (OVER, PARTITION BY).
     */
    public function supportsWindowFunctions(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => $this->sqliteVersionAtLeast('3.25.0'),
        };
    }

    /**
     * Whether the driver supports Common Table Expressions (WITH ... AS).
     */
    public function supportsCte(): bool
    {
        return match ($this->driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
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
