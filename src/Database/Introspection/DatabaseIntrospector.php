<?php

declare(strict_types=1);

namespace Pulsar\Database\Introspection;

use function array_map;
use function in_array;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;

use function strtolower;

/**
 * Introspects database schema using driver-specific queries.
 *
 * Works directly with ConnectionInterface — no ORM required.
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseIntrospector
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * List all user tables in the database.
     *
     * @return list<TableInfo>
     */
    public function tables(): array
    {
        return match ($this->connection->driver()) {
            Driver::SQLite => $this->sqliteTables(),
            Driver::MySQL => $this->mysqlTables(),
            Driver::PostgreSQL => $this->pgsqlTables(),
        };
    }

    /**
     * List all columns for a given table.
     *
     * @return list<ColumnInfo>
     */
    public function columns(string $table): array
    {
        return match ($this->connection->driver()) {
            Driver::SQLite => $this->sqliteColumns($table),
            Driver::MySQL => $this->mysqlColumns($table),
            Driver::PostgreSQL => $this->pgsqlColumns($table),
        };
    }

    /**
     * Determine the primary key column for a table, or null if none.
     */
    public function primaryKey(string $table): ?string
    {
        $columns = $this->columns($table);

        foreach ($columns as $column) {
            if ($column->isPrimaryKey) {
                return $column->name;
            }
        }

        return null;
    }

    /**
     * @return list<TableInfo>
     */
    private function sqliteTables(): array
    {
        $result = $this->connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        return array_map(
            static fn(Row $row): TableInfo => new TableInfo(name: $row->getString('name')),
            $result->rows,
        );
    }

    /**
     * @return list<TableInfo>
     */
    private function mysqlTables(): array
    {
        $result = $this->connection->query('SHOW TABLES');

        return array_map(
            static function (Row $row): TableInfo {
                $data = $row->toArray();
                /** @var string $name */
                $name = reset($data);

                return new TableInfo(name: $name);
            },
            $result->rows,
        );
    }

    /**
     * @return list<TableInfo>
     */
    private function pgsqlTables(): array
    {
        $result = $this->connection->query(
            "SELECT table_name, table_schema FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name",
        );

        return array_map(
            static fn(Row $row): TableInfo => new TableInfo(
                name: $row->getString('table_name'),
                schema: $row->getString('table_schema'),
            ),
            $result->rows,
        );
    }

    /**
     * @return list<ColumnInfo>
     */
    private function sqliteColumns(string $table): array
    {
        $result = $this->connection->query("PRAGMA table_info($table)");

        return array_map(
            static fn(Row $row): ColumnInfo => new ColumnInfo(
                name: $row->getString('name'),
                type: strtolower($row->getString('type')),
                nullable: $row->getInt('notnull') === 0,
                isPrimaryKey: $row->getInt('pk') > 0,
                default: $row->getNullableString('dflt_value'),
            ),
            $result->rows,
        );
    }

    /**
     * @return list<ColumnInfo>
     */
    private function mysqlColumns(string $table): array
    {
        $result = $this->connection->query("SHOW COLUMNS FROM $table");

        return array_map(
            static fn(Row $row): ColumnInfo => new ColumnInfo(
                name: $row->getString('Field'),
                type: strtolower($row->getString('Type')),
                nullable: $row->getString('Null') === 'YES',
                isPrimaryKey: $row->getString('Key') === 'PRI',
                default: $row->getNullableString('Default'),
            ),
            $result->rows,
        );
    }

    /**
     * @return list<ColumnInfo>
     */
    private function pgsqlColumns(string $table): array
    {
        $columnResult = $this->connection->query(
            'SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = :table ORDER BY ordinal_position',
            ['table' => $table],
        );

        $pkResult = $this->connection->query(
            'SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = :table::regclass AND i.indisprimary',
            ['table' => $table],
        );

        $pkColumns = array_map(
            static fn(Row $row): string => $row->getString('attname'),
            $pkResult->rows,
        );

        return array_map(
            static fn(Row $row): ColumnInfo => new ColumnInfo(
                name: $row->getString('column_name'),
                type: strtolower($row->getString('data_type')),
                nullable: $row->getString('is_nullable') === 'YES',
                isPrimaryKey: in_array($row->getString('column_name'), $pkColumns, true),
                default: $row->getNullableString('column_default'),
            ),
            $columnResult->rows,
        );
    }
}
