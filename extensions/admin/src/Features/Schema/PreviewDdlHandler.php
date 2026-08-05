<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaIdentifier;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;

/**
 * Generates DDL preview without executing. Returns SQL + capability warnings.
 *
 * Identifiers are validated here for the same reason {@see AlterTableHandler} validates
 * them: both are reached by the same request body, and only one of them used to check.
 * A preview request missing its `name` reached the compiler as an empty identifier and
 * left the controller as a 500 — the operator's malformed input reported as a server
 * fault. Rejecting it here yields the 400 it is.
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
        SchemaIdentifier::validateTable($table);
        SchemaIdentifier::validateColumn($column->name);

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
        // Before the capability check: a malformed identifier is malformed on every engine.
        SchemaIdentifier::validateTable($table);
        SchemaIdentifier::validateColumn($column);

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
        SchemaIdentifier::validateTable($table);
        SchemaIdentifier::validateIndex($index->name);

        foreach ($index->columns as $column) {
            SchemaIdentifier::validateColumn($column);
        }

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
        SchemaIdentifier::validateTable($table);
        SchemaIdentifier::validateIndex($indexName);

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
        SchemaIdentifier::validateTable($table);

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
        SchemaIdentifier::validateTable($from);
        SchemaIdentifier::validateTable($to);

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
