<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaDefaultExpression;
use Pulsar\Database\Schema\SchemaForeignKey;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaReferentialAction;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Extension\Admin\Features\Schema\AlterTableHandler;
use Pulsar\Extension\Admin\Features\Schema\CreateTableHandler;
use Pulsar\Extension\Admin\Features\Schema\DropTableHandler;
use Pulsar\Extension\Admin\Features\Schema\PreviewDdlHandler;
use Pulsar\Extension\Admin\Features\Schema\RenameTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function array_map;
use function is_string;
use function mb_strlen;

/**
 * Admin schema builder JSON API controller.
 */
#[Internal]
final readonly class SchemaApiController
{
    public function __construct(
        private readonly CreateTableHandler $createHandler,
        private readonly AlterTableHandler $alterHandler,
        private readonly DropTableHandler $dropHandler,
        private readonly RenameTableHandler $renameHandler,
        private readonly PreviewDdlHandler $previewHandler,
        private readonly SchemaChangeLogStoreInterface $changeLog,
    ) {}

    public function create(Request $request): Response
    {
        $context = $this->extractContext($request);
        $definition = $this->buildTableDefinition($request);

        $result = $this->createHandler->execute($definition, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created : ResponseStatus::BadRequest,
        );
    }

    public function dropTable(Request $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $result = $this->dropHandler->execute($table, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK : ResponseStatus::BadRequest,
        );
    }

    public function renameTable(Request $request, string $table): Response
    {
        $context = $this->extractContext($request);
        /** @var string $newName */
        $newName = $request->input('new_name', '') ?? '';
        $result = $this->renameHandler->execute($table, $newName, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK : ResponseStatus::BadRequest,
        );
    }

    public function addColumn(Request $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $column = $this->buildColumn($request);

        $result = $this->alterHandler->addColumn($table, $column, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created : ResponseStatus::BadRequest,
        );
    }

    public function dropColumn(Request $request, string $table, string $col): Response
    {
        $context = $this->extractContext($request);
        $result = $this->alterHandler->dropColumn($table, $col, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK : ResponseStatus::BadRequest,
        );
    }

    public function addIndex(Request $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $index = $this->buildIndex($request);

        $result = $this->alterHandler->addIndex($table, $index, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created : ResponseStatus::BadRequest,
        );
    }

    public function dropIndex(Request $request, string $table, string $name): Response
    {
        $context = $this->extractContext($request);
        $result = $this->alterHandler->dropIndex($table, $name, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK : ResponseStatus::BadRequest,
        );
    }

    // Preview endpoints

    public function previewCreate(Request $request): Response
    {
        $definition = $this->buildTableDefinition($request);
        $result = $this->previewHandler->previewCreate($definition);

        return Response::json($result);
    }

    public function previewAddColumn(Request $request, string $table): Response
    {
        $column = $this->buildColumn($request);
        $result = $this->previewHandler->previewAddColumn($table, $column);

        return Response::json($result);
    }

    public function previewDropColumn(Request $request, string $table): Response
    {
        /** @var string $column */
        $column = $request->input('column', '') ?? '';
        $result = $this->previewHandler->previewDropColumn($table, $column);

        return Response::json($result);
    }

    public function previewAddIndex(Request $request, string $table): Response
    {
        $index = $this->buildIndex($request);
        $result = $this->previewHandler->previewAddIndex($table, $index);

        return Response::json($result);
    }

    public function previewDropIndex(Request $request, string $table): Response
    {
        /** @var string $indexName */
        $indexName = $request->input('name', '') ?? '';
        $result = $this->previewHandler->previewDropIndex($table, $indexName);

        return Response::json($result);
    }

    public function previewDropTable(Request $request, string $table): Response
    {
        $result = $this->previewHandler->previewDropTable($table);

        return Response::json($result);
    }

    public function previewRenameTable(Request $request, string $table): Response
    {
        /** @var string $newName */
        $newName = $request->input('new_name', '') ?? '';
        $result = $this->previewHandler->previewRenameTable($table, $newName);

        return Response::json($result);
    }

    public function changelog(Request $request): Response
    {
        $limit = (int) ($request->input('limit', '100') ?? '100');
        $entries = $this->changeLog->recent($limit);

        $entryData = array_map(
            static fn($entry): array => [
                'id' => $entry->id,
                'operation' => $entry->operation,
                'table' => $entry->table,
                'actor' => $entry->actor,
                'reason' => $entry->reason,
                'timestamp' => $entry->timestamp,
                'evidence_hash' => $entry->evidenceHash,
                'success' => $entry->success,
                'statements' => $entry->statements,
            ],
            $entries,
        );

        return Response::json(['entries' => $entryData]);
    }

    public function exportBundle(Request $request): Response
    {
        $bundle = $this->changeLog->exportSqlBundle();

        return new Response(
            body: $bundle,
            status: ResponseStatus::OK,
            headers: new \Pulsar\Http\HeaderBag([
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="schema-changelog.sql"',
            ]),
        );
    }

    private function extractContext(Request $request): MutationContext
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        /** @var string $reason */
        $reason = $request->input('reason', '') ?? '';

        if (!is_string($reason) || mb_strlen($reason) < 5) {
            throw new \Pulsar\Extension\Admin\Exception\AdminException(
                'A reason of at least 5 characters is required for schema operations',
            );
        }

        return new MutationContext(
            actor: $actor,
            reason: $reason,
        );
    }

    private function buildTableDefinition(Request $request): TableDefinition
    {
        /** @var string $name */
        $name = $request->input('name', '') ?? '';

        /** @var list<array<string, mixed>> $columnsData */
        $columnsData = $request->input('columns', []) ?? [];

        /** @var list<array<string, mixed>> $indexesData */
        $indexesData = $request->input('indexes', []) ?? [];

        /** @var list<array<string, mixed>> $foreignKeysData */
        $foreignKeysData = $request->input('foreign_keys', []) ?? [];

        $columns = array_map(fn(array $c): SchemaColumn => $this->buildColumnFromArray($c), $columnsData);
        $indexes = array_map(fn(array $i): SchemaIndex => $this->buildIndexFromArray($i), $indexesData);
        $foreignKeys = array_map(fn(array $fk): SchemaForeignKey => $this->buildForeignKeyFromArray($fk), $foreignKeysData);

        return new TableDefinition(
            name: $name,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    private function buildColumn(Request $request): SchemaColumn
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();

        return $this->buildColumnFromArray($data);
    }

    private function buildIndex(Request $request): SchemaIndex
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();

        return $this->buildIndexFromArray($data);
    }

    /**
     * @param array<string, mixed> $c
     */
    private function buildColumnFromArray(array $c): SchemaColumn
    {
        $type = SchemaColumnType::from((string) ($c['type'] ?? 'string'));

        $defaultExpression = null;
        if (isset($c['default_expression']) && is_string($c['default_expression']) && $c['default_expression'] !== '') {
            $defaultExpression = SchemaDefaultExpression::from($c['default_expression']);
        }

        /** @var list<string> $enumValues */
        $enumValues = $c['enum_values'] ?? [];

        return new SchemaColumn(
            name: (string) ($c['name'] ?? ''),
            type: $type,
            nullable: (bool) ($c['nullable'] ?? false),
            primaryKey: (bool) ($c['primary_key'] ?? false),
            autoIncrement: (bool) ($c['auto_increment'] ?? false),
            unsigned: (bool) ($c['unsigned'] ?? false),
            unique: (bool) ($c['unique'] ?? false),
            default: $c['default_value'] ?? null,
            hasDefault: (bool) ($c['has_default'] ?? false),
            defaultExpression: $defaultExpression,
            length: isset($c['length']) ? (int) $c['length'] : null,
            precision: isset($c['precision']) ? (int) $c['precision'] : null,
            scale: isset($c['scale']) ? (int) $c['scale'] : null,
            enumValues: $enumValues,
        );
    }

    /**
     * @param array<string, mixed> $i
     */
    private function buildIndexFromArray(array $i): SchemaIndex
    {
        /** @var list<string> $columns */
        $columns = $i['columns'] ?? [];

        return new SchemaIndex(
            name: (string) ($i['name'] ?? ''),
            columns: $columns,
            unique: (bool) ($i['unique'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $fk
     */
    private function buildForeignKeyFromArray(array $fk): SchemaForeignKey
    {
        /** @var list<string> $columns */
        $columns = $fk['columns'] ?? [];
        /** @var list<string> $referencedColumns */
        $referencedColumns = $fk['referenced_columns'] ?? [];

        return new SchemaForeignKey(
            name: (string) ($fk['name'] ?? ''),
            columns: $columns,
            referencedTable: (string) ($fk['referenced_table'] ?? ''),
            referencedColumns: $referencedColumns,
            onDelete: SchemaReferentialAction::from((string) ($fk['on_delete'] ?? 'RESTRICT')),
            onUpdate: SchemaReferentialAction::from((string) ($fk['on_update'] ?? 'RESTRICT')),
        );
    }
}
