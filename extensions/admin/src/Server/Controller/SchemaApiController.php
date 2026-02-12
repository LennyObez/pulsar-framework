<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
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
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Features\Schema\AlterTableHandler;
use Pulsar\Extension\Admin\Features\Schema\CreateTableHandler;
use Pulsar\Extension\Admin\Features\Schema\DropTableHandler;
use Pulsar\Extension\Admin\Features\Schema\PreviewDdlHandler;
use Pulsar\Extension\Admin\Features\Schema\RenameTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Http\Message\Response;
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
        private CreateTableHandler $createHandler,
        private AlterTableHandler $alterHandler,
        private DropTableHandler $dropHandler,
        private RenameTableHandler $renameHandler,
        private PreviewDdlHandler $previewHandler,
        private SchemaChangeLogStoreInterface $changeLog,
    ) {}

    public function create(ServerRequestInterface $request): Response
    {
        $context = $this->extractContext($request);
        $definition = $this->buildTableDefinition($request);

        $result = $this->createHandler->execute($definition, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created->value : ResponseStatus::BadRequest->value,
        );
    }

    public function dropTable(ServerRequestInterface $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $result = $this->dropHandler->execute($table, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK->value : ResponseStatus::BadRequest->value,
        );
    }

    public function renameTable(ServerRequestInterface $request, string $table): Response
    {
        $context = $this->extractContext($request);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var string $newName */
        $newName = $body['new_name'] ?? '';
        $result = $this->renameHandler->execute($table, $newName, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK->value : ResponseStatus::BadRequest->value,
        );
    }

    public function addColumn(ServerRequestInterface $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $column = $this->buildColumn($request);

        $result = $this->alterHandler->addColumn($table, $column, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created->value : ResponseStatus::BadRequest->value,
        );
    }

    public function dropColumn(ServerRequestInterface $request, string $table, string $col): Response
    {
        $context = $this->extractContext($request);
        $result = $this->alterHandler->dropColumn($table, $col, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK->value : ResponseStatus::BadRequest->value,
        );
    }

    public function addIndex(ServerRequestInterface $request, string $table): Response
    {
        $context = $this->extractContext($request);
        $index = $this->buildIndex($request);

        $result = $this->alterHandler->addIndex($table, $index, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::Created->value : ResponseStatus::BadRequest->value,
        );
    }

    public function dropIndex(ServerRequestInterface $request, string $table, string $name): Response
    {
        $context = $this->extractContext($request);
        $result = $this->alterHandler->dropIndex($table, $name, $context);

        return Response::json(
            $result,
            $result['success'] ? ResponseStatus::OK->value : ResponseStatus::BadRequest->value,
        );
    }

    // Preview endpoints

    public function previewCreate(ServerRequestInterface $request): Response
    {
        $definition = $this->buildTableDefinition($request);
        $result = $this->previewHandler->previewCreate($definition);

        return Response::json($result);
    }

    public function previewAddColumn(ServerRequestInterface $request, string $table): Response
    {
        $column = $this->buildColumn($request);
        $result = $this->previewHandler->previewAddColumn($table, $column);

        return Response::json($result);
    }

    public function previewDropColumn(ServerRequestInterface $request, string $table): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var string $column */
        $column = $body['column'] ?? '';
        $result = $this->previewHandler->previewDropColumn($table, $column);

        return Response::json($result);
    }

    public function previewAddIndex(ServerRequestInterface $request, string $table): Response
    {
        $index = $this->buildIndex($request);
        $result = $this->previewHandler->previewAddIndex($table, $index);

        return Response::json($result);
    }

    public function previewDropIndex(ServerRequestInterface $request, string $table): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var string $indexName */
        $indexName = $body['name'] ?? '';
        $result = $this->previewHandler->previewDropIndex($table, $indexName);

        return Response::json($result);
    }

    public function previewDropTable(ServerRequestInterface $request, string $table): Response
    {
        $result = $this->previewHandler->previewDropTable($table);

        return Response::json($result);
    }

    public function previewRenameTable(ServerRequestInterface $request, string $table): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var string $newName */
        $newName = $body['new_name'] ?? '';
        $result = $this->previewHandler->previewRenameTable($table, $newName);

        return Response::json($result);
    }

    public function changelog(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $limit = (int) ($body['limit'] ?? '100');
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

    public function exportBundle(ServerRequestInterface $request): Response
    {
        $bundle = $this->changeLog->exportSqlBundle();

        return new Response(
            statusCode: ResponseStatus::OK->value,
            headers: [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="schema-changelog.sql"',
            ],
            body: $bundle,
        );
    }

    private function extractContext(ServerRequestInterface $request): MutationContext
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $reason = $body['reason'] ?? '';

        if (!is_string($reason) || mb_strlen($reason) < 5) {
            throw new AdminException(
                'A reason of at least 5 characters is required for schema operations',
            );
        }

        return new MutationContext(
            actor: $actor,
            reason: $reason,
        );
    }

    private function buildTableDefinition(ServerRequestInterface $request): TableDefinition
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var string $name */
        $name = $body['name'] ?? '';

        /** @var list<array<string, mixed>> $columnsData */
        $columnsData = $body['columns'] ?? [];

        /** @var list<array<string, mixed>> $indexesData */
        $indexesData = $body['indexes'] ?? [];

        /** @var list<array<string, mixed>> $foreignKeysData */
        $foreignKeysData = $body['foreign_keys'] ?? [];

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

    private function buildColumn(ServerRequestInterface $request): SchemaColumn
    {
        /** @var array<string, mixed> $data */
        $data = (array) ($request->getParsedBody() ?? []);

        return $this->buildColumnFromArray($data);
    }

    private function buildIndex(ServerRequestInterface $request): SchemaIndex
    {
        /** @var array<string, mixed> $data */
        $data = (array) ($request->getParsedBody() ?? []);

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
