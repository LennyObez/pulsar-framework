<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Generator;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\AbstractGenerator;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\OverwritePolicy;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\DiffResult;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\SchemaOperation;
use Pulsar\Codegen\Schema\SchemaOperationType;
use Pulsar\Codegen\Template\TemplateRenderer;
use Pulsar\Codegen\Template\TemplateVariable;

use function count;
use function implode;
use function is_string;
use function rtrim;
use function str_replace;
use function strtolower;
use function strtoupper;

/**
 * Generates migration files from a DiffResult.
 *
 * Produces anonymous-class migration files implementing MigrationInterface,
 * matching Pulsar's existing migration system. Each migration uses
 * ConnectionInterface::execute() with raw DDL statements.
 *
 * Filename format: `{YYYYMMDDHHMMSS}_description.php` matching MigrationRepository.
 * @api
 */
#[Api(since: '1.0.0')]
final class MigrationGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        use Pulsar\Database\ConnectionInterface;
        use Pulsar\Database\Migration\MigrationInterface;

        return new class implements MigrationInterface {
            public function up(ConnectionInterface $connection): void
            {
        {{upBody}}
            }

            public function down(ConnectionInterface $connection): void
            {
        {{downBody}}
            }
        };
        TPL;

    public function __construct(
        TemplateRenderer $renderer,
        PathValidator $pathValidator,
        private readonly ?DiffResult $diffResult = null,
        private readonly string $timestamp = '',
        private readonly string $migrationName = '',
    ) {
        parent::__construct($renderer, $pathValidator);
    }

    /** @return list<GeneratedFile> */
    #[Override]
    protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array
    {
        if ($this->diffResult === null || ! $this->diffResult->hasChanges()) {
            return [];
        }

        $baseDir = rtrim($config->outputBaseDirectory, '/');
        $ts = $this->timestamp !== '' ? $this->timestamp : '00000000000000';
        $name = $this->migrationName !== '' ? $this->migrationName : $entity->tableName;
        $fileName = $ts . '_' . $name . '.php';
        $policy = $config->force ? OverwritePolicy::Force : OverwritePolicy::Fail;

        $upBody = $this->buildUpStatements($this->diffResult);
        $downBody = $this->buildDownStatements($this->diffResult);

        $variables = [
            new TemplateVariable('upBody', $upBody),
            new TemplateVariable('downBody', $downBody),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/database/migrations/' . $fileName,
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    /**
     * Build the up() method body from diff operations.
     */
    private function buildUpStatements(DiffResult $diff): string
    {
        $lines = [];

        foreach ($diff->operations as $op) {
            $stmt = $this->operationToUpStatement($op);

            if ($stmt !== '') {
                $lines[] = $stmt;
            }
        }

        if ($lines === []) {
            $lines[] = '        // No operations';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Build the down() method body by reversing operations.
     */
    private function buildDownStatements(DiffResult $diff): string
    {
        $lines = [];
        $ops = $diff->operations;

        for ($i = count($ops) - 1; $i >= 0; $i--) {
            $stmt = $this->operationToDownStatement($ops[$i]);

            if ($stmt !== '') {
                $lines[] = $stmt;
            }
        }

        if ($lines === []) {
            $lines[] = '        // No operations';
        }

        return implode("\n\n", $lines);
    }

    private function operationToUpStatement(SchemaOperation $op): string
    {
        return match ($op->type) {
            SchemaOperationType::CreateTable => $this->createTableSql($op),
            SchemaOperationType::DropTable => $this->dropTableSql($op),
            SchemaOperationType::AddColumn => $this->addColumnSql($op),
            SchemaOperationType::DropColumn => $this->dropColumnSql($op),
            SchemaOperationType::ModifyColumn => $this->modifyColumnSql($op),
            SchemaOperationType::AddIndex => $this->addIndexSql($op),
            SchemaOperationType::DropIndex => $this->dropIndexSql($op),
            SchemaOperationType::AddForeignKey => $this->addForeignKeySql($op),
            SchemaOperationType::DropForeignKey => $this->dropForeignKeySql($op),
        };
    }

    private function operationToDownStatement(SchemaOperation $op): string
    {
        return match ($op->type) {
            SchemaOperationType::CreateTable => $this->dropTableSql($op),
            SchemaOperationType::DropTable => $this->createTableSql($op),
            SchemaOperationType::AddColumn => $this->dropColumnSql($op),
            SchemaOperationType::DropColumn => $this->addColumnSql($op),
            SchemaOperationType::ModifyColumn => $this->reverseModifyColumnSql($op),
            SchemaOperationType::AddIndex => $this->dropIndexSql($op),
            SchemaOperationType::DropIndex => $this->addIndexSql($op),
            SchemaOperationType::AddForeignKey => $this->dropForeignKeySql($op),
            SchemaOperationType::DropForeignKey => $this->addForeignKeySql($op),
        };
    }

    private function createTableSql(SchemaOperation $op): string
    {
        return "        \$connection->execute(\n"
            . "            'CREATE TABLE IF NOT EXISTS $op->table (id INTEGER PRIMARY KEY)'\n"
            . '        );';
    }

    private function dropTableSql(SchemaOperation $op): string
    {
        return "        \$connection->execute('DROP TABLE IF EXISTS $op->table');";
    }

    private function addColumnSql(SchemaOperation $op): string
    {
        $columnType = $this->mapToSqlType($this->meta($op, 'columnType', 'varchar(255)'));
        $nullable = ($op->metadata['nullable'] ?? false) === true ? '' : ' NOT NULL';
        $column = $op->column ?? 'unknown';

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table ADD COLUMN $column $columnType$nullable'\n"
            . '        );';
    }

    private function dropColumnSql(SchemaOperation $op): string
    {
        $column = $op->column ?? 'unknown';

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table DROP COLUMN $column'\n"
            . '        );';
    }

    private function modifyColumnSql(SchemaOperation $op): string
    {
        $newType = $this->mapToSqlType($this->meta($op, 'newType', 'varchar(255)'));
        $nullable = ($op->metadata['newNullable'] ?? false) === true ? '' : ' NOT NULL';
        $column = $op->column ?? 'unknown';

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table ALTER COLUMN $column TYPE $newType$nullable'\n"
            . '        );';
    }

    private function reverseModifyColumnSql(SchemaOperation $op): string
    {
        $oldType = $this->mapToSqlType($this->meta($op, 'oldType', 'varchar(255)'));
        $nullable = ($op->metadata['oldNullable'] ?? false) === true ? '' : ' NOT NULL';
        $column = $op->column ?? 'unknown';

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table ALTER COLUMN $column TYPE $oldType$nullable'\n"
            . '        );';
    }

    private function addIndexSql(SchemaOperation $op): string
    {
        $column = $op->column ?? 'unknown';
        $indexName = $this->meta($op, 'indexName', $op->table . '_' . $column . '_idx');

        return "        \$connection->execute(\n"
            . "            'CREATE INDEX $indexName ON $op->table ($column)'\n"
            . '        );';
    }

    private function dropIndexSql(SchemaOperation $op): string
    {
        $column = $op->column ?? 'unknown';
        $indexName = $this->meta($op, 'indexName', $op->table . '_' . $column . '_idx');

        return "        \$connection->execute('DROP INDEX IF EXISTS $indexName');";
    }

    private function addForeignKeySql(SchemaOperation $op): string
    {
        $column = $op->column ?? 'unknown';
        $relatedEntity = $this->meta($op, 'relatedEntity', '');
        $localKey = $this->meta($op, 'localKey', 'id');
        $fkName = $op->table . '_' . $column . '_fk';

        // Derive table name from related entity (lowercase + 's' as convention)
        $refTable = $relatedEntity !== '' ? str_replace('\\', '', $relatedEntity) . 's' : 'unknown';
        $refTable = strtolower($refTable);

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table ADD CONSTRAINT $fkName "
            . "FOREIGN KEY ($column) REFERENCES $refTable ($localKey)'\n"
            . '        );';
    }

    private function dropForeignKeySql(SchemaOperation $op): string
    {
        $column = $op->column ?? 'unknown';
        $fkName = $this->meta($op, 'constraintName', $op->table . '_' . $column . '_fk');

        return "        \$connection->execute(\n"
            . "            'ALTER TABLE $op->table DROP CONSTRAINT IF EXISTS $fkName'\n"
            . '        );';
    }

    /**
     * Safely extract a string value from operation metadata.
     */
    private function meta(SchemaOperation $op, string $key, string $default): string
    {
        /** @var mixed $value */
        $value = $op->metadata[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Map schema column types to SQL DDL types.
     */
    private function mapToSqlType(string $columnType): string
    {
        return match ($columnType) {
            'int', 'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'bool', 'boolean' => 'BOOLEAN',
            'float', 'double', 'real' => 'DOUBLE PRECISION',
            'decimal', 'numeric' => 'DECIMAL',
            'text', 'mediumtext', 'longtext' => 'TEXT',
            'json', 'jsonb' => 'JSON',
            'datetime', 'timestamp' => 'TIMESTAMP',
            'date' => 'DATE',
            'uuid' => 'UUID',
            'blob', 'binary' => 'BLOB',
            default => strtoupper($columnType),
        };
    }
}
