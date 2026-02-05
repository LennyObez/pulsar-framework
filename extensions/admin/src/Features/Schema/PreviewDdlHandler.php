<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;

/**
 * Generates DDL preview without executing. Returns SQL + capability warnings.
 */
final readonly class PreviewDdlHandler
{
    public function __construct(
        private SchemaManager $schemaManager,
        private SchemaCapabilities $capabilities,
    ) {}

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewCreate(TableDefinition $definition): array
    {
        $statements = $this->schemaManager->previewCreateTable($definition);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewAddColumn(string $table, SchemaColumn $column): array
    {
        $statements = $this->schemaManager->previewAddColumn($table, $column);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewDropColumn(string $table, string $column): array
    {
        if (!$this->capabilities->supportsDropColumn()) {
            return [
                'statements' => [],
                'warnings' => [
                    ['code' => 'SQLITE_NO_DROP_COLUMN', 'message' => 'SQLite does not support DROP COLUMN on this version'],
                ],
            ];
        }

        $statements = $this->schemaManager->previewDropColumn($table, $column);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewAddIndex(string $table, SchemaIndex $index): array
    {
        $statements = $this->schemaManager->previewAddIndex($table, $index);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewDropIndex(string $table, string $indexName): array
    {
        $statements = $this->schemaManager->previewDropIndex($table, $indexName);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewDropTable(string $table): array
    {
        $statements = $this->schemaManager->previewDropTable($table);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return array{statements: list<string>, warnings: list<array{code: string, message: string}>}
     */
    public function previewRenameTable(string $from, string $to): array
    {
        $statements = $this->schemaManager->previewRenameTable($from, $to);

        return [
            'statements' => $statements,
            'warnings' => $this->collectWarnings(),
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function collectWarnings(): array
    {
        /** @var list<array{code: string, message: string}> */
        return array_values(array_filter([
            !$this->capabilities->supportsDropColumn()
                ? ['code' => 'SQLITE_NO_DROP_COLUMN', 'message' => 'This database does not support DROP COLUMN']
                : null,
            !$this->capabilities->supportsAlterColumnType()
                ? ['code' => 'NO_ALTER_COLUMN_TYPE', 'message' => 'This database does not support altering column types']
                : null,
            !$this->capabilities->foreignKeysEnforcedByDefault()
                ? ['code' => 'FK_PRAGMA_REQUIRED', 'message' => 'Foreign keys require PRAGMA foreign_keys = ON (enabled by Pulsar)']
                : null,
            !$this->capabilities->supportsTransactionalDdl()
                ? ['code' => 'NO_TRANSACTIONAL_DDL', 'message' => 'Schema changes are not atomic on this database driver']
                : null,
            !$this->capabilities->supportsAddForeignKey()
                ? ['code' => 'NO_ALTER_ADD_FK', 'message' => 'Foreign keys can only be defined at table creation time']
                : null,
        ]));
    }
}
