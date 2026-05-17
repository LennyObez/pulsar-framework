<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;

use function array_diff;
use function array_map;
use function date;
use function implode;
use function in_array;
use function sprintf;
use function strtolower;

/**
 * Compares entity metadata with database schema and generates migration SQL.
 *
 * Produces a complete migration file that can be written to the migration
 * directory and executed by MigrationRunner.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiffGenerator
{
    public function __construct(
        private MetadataRegistryInterface $metadataRegistry,
        private DatabaseIntrospector $introspector,
    ) {}

    /**
     * Generate migration content for all registered entities.
     *
     * @param list<class-string> $entityClasses Entity classes to diff
     * @return DiffResult The generated migration with up/down SQL
     * @throws DatabaseException
     */
    public function generate(array $entityClasses): DiffResult
    {
        $upStatements = [];
        $downStatements = [];

        $existingTables = array_map(
            static fn(object $t): string => $t->name,
            $this->introspector->tables(),
        );

        foreach ($entityClasses as $entityClass) {
            $metadata = $this->metadataRegistry->get($entityClass);
            $tableName = $metadata->tableName;

            if (!in_array($tableName, $existingTables, true)) {
                // Table does not exist: generate CREATE TABLE
                $createSql = $this->compileCreateTable($metadata);
                $upStatements[] = $createSql;
                $downStatements[] = sprintf('DROP TABLE IF EXISTS %s', $tableName);
            } else {
                // Table exists: diff columns
                $columnDiff = $this->diffColumns($metadata);
                foreach ($columnDiff->addColumns as $col) {
                    $upStatements[] = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s %s%s',
                        $tableName,
                        $col->columnName,
                        $this->mapColumnTypeSql($col->type),
                        $col->nullable ? '' : ' NOT NULL',
                    );
                    $downStatements[] = sprintf(
                        'ALTER TABLE %s DROP COLUMN %s',
                        $tableName,
                        $col->columnName,
                    );
                }

                foreach ($columnDiff->dropColumns as $colName) {
                    $upStatements[] = sprintf(
                        'ALTER TABLE %s DROP COLUMN %s',
                        $tableName,
                        $colName,
                    );
                    // Down for dropped columns is not auto-reversible
                    $downStatements[] = sprintf(
                        '-- Cannot auto-reverse: column %s.%s was dropped',
                        $tableName,
                        $colName,
                    );
                }
            }
        }

        if ($upStatements === [] && $downStatements === []) {
            return DiffResult::noChanges();
        }

        $version = date('YmdHis');
        $migrationContent = $this->renderMigrationFile($upStatements, $downStatements);

        return new DiffResult(
            version: $version,
            content: $migrationContent,
            upStatements: $upStatements,
            downStatements: $downStatements,
            hasChanges: true,
        );
    }

    private function compileCreateTable(EntityMetadata $metadata): string
    {
        $columnDefs = [];

        // Primary key
        $pk = $metadata->primaryKey;
        $pkType = $this->mapColumnTypeSql($pk->type);
        $autoIncrement = $pk->autoIncrement ? ' PRIMARY KEY AUTOINCREMENT' : ' PRIMARY KEY';
        $columnDefs[] = sprintf('%s %s%s', $pk->columnName, $pkType, $autoIncrement);

        // Regular columns
        foreach ($metadata->columns as $col) {
            if ($col->isPrimaryKey) {
                continue;
            }
            $nullable = $col->nullable ? '' : ' NOT NULL';
            $columnDefs[] = sprintf('%s %s%s', $col->columnName, $this->mapColumnTypeSql($col->type), $nullable);
        }

        return sprintf(
            'CREATE TABLE %s (%s)',
            $metadata->tableName,
            implode(', ', $columnDefs),
        );
    }

    private function diffColumns(EntityMetadata $metadata): ColumnDiff
    {
        $dbColumns = $this->introspector->columns($metadata->tableName);
        $dbColumnNames = array_map(
            static fn(ColumnInfo $c): string => strtolower($c->name),
            $dbColumns,
        );

        $entityColumnNames = [];
        /** @var array<string, ColumnMetadata> $entityColumnMap */
        $entityColumnMap = [];
        foreach ($metadata->columns as $col) {
            $entityColumnNames[] = strtolower($col->columnName);
            $entityColumnMap[strtolower($col->columnName)] = $col;
        }

        $toAdd = array_diff($entityColumnNames, $dbColumnNames);
        $toDrop = array_diff($dbColumnNames, $entityColumnNames);

        $addColumns = [];
        foreach ($toAdd as $name) {
            $addColumns[] = $entityColumnMap[$name];
        }

        return new ColumnDiff(
            addColumns: $addColumns,
            dropColumns: array_values($toDrop),
        );
    }

    private function mapColumnTypeSql(ColumnType $type): string
    {
        return match ($type) {
            ColumnType::String => 'VARCHAR(255)',
            ColumnType::Integer, ColumnType::SmallInt => 'INTEGER',
            ColumnType::BigInt => 'BIGINT',
            ColumnType::Float, ColumnType::Decimal => 'REAL',
            ColumnType::Boolean => 'BOOLEAN',
            ColumnType::Text => 'TEXT',
            ColumnType::Binary => 'BLOB',
            ColumnType::DateTime => 'TIMESTAMP',
            ColumnType::Date => 'DATE',
            ColumnType::Time => 'TIME',
            ColumnType::Json => 'TEXT',
            ColumnType::Uuid => 'VARCHAR(36)',
            ColumnType::Enum => 'VARCHAR(255)',
        };
    }

    /**
     * @param list<string> $upStatements
     * @param list<string> $downStatements
     */
    private function renderMigrationFile(array $upStatements, array $downStatements): string
    {
        $upBody = '';
        foreach ($upStatements as $sql) {
            $upBody .= sprintf("            \$connection->execute('%s');\n", addslashes($sql));
        }

        $downBody = '';
        foreach ($downStatements as $sql) {
            $downBody .= sprintf("            \$connection->execute('%s');\n", addslashes($sql));
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface \$connection): void
                {
            {$upBody}    }

                public function down(ConnectionInterface \$connection): void
                {
            {$downBody}    }
            };

            PHP;
    }
}
