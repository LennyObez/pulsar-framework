<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;

/**
 * Reports driver-specific DDL capabilities.
 *
 * Used by the admin UI and DdlCompiler to guard operations that
 * are unsupported on certain drivers (e.g., DROP COLUMN on older SQLite).
 */
#[Api(since: '1.0.0')]
final readonly class SchemaCapabilities
{
    public function __construct(
        private Driver $driver,
        private ?ConnectionInterface $connection = null,
    ) {}

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
            Driver::SQLite => $this->sqliteVersionAtLeast('3.35.0'),
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
     * Export capabilities as an associative array for frontend consumption.
     *
     * @return array<string, bool>
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
        ];
    }

    private function sqliteVersionAtLeast(string $minVersion): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $result = $this->connection->query('SELECT sqlite_version() AS version');
        if ($result->rows === []) {
            return false;
        }

        $version = $result->rows[0]->getString('version');

        return version_compare($version, $minVersion, '>=');
    }
}
