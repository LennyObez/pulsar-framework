<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function count;
use function str_contains;

/**
 * Admin schema builder HTML page controller.
 */
#[Internal]
final readonly class SchemaController
{
    public function __construct(
        private DatabaseIntrospector $introspector,
        private SchemaCapabilities $capabilities,
        private AdminSchemaConfig $config,
        private SchemaChangeLogStoreInterface $changeLog,
    ) {}

    public function list(ServerRequestInterface $request): Response
    {
        $tables = $this->introspector->tables();

        $tableData = array_map(
            function ($tableInfo): array {
                $columns = $this->introspector->columns($tableInfo->name);
                $pk = $this->introspector->primaryKey($tableInfo->name);

                return [
                    'name' => $tableInfo->name,
                    'columns' => count($columns),
                    'primaryKey' => $pk,
                ];
            },
            $tables,
        );

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json([
                'tables' => $tableData,
                'capabilities' => $this->capabilities->toArray(),
            ]);
        }

        return Response::html($this->renderHtml('schema/list', 'Database', [
            'tables' => $tableData,
            'capabilities' => $this->capabilities->toArray(),
            'driver' => $this->capabilities->supportsNativeEnum() ? 'mysql' : ($this->capabilities->supportsTransactionalDdl() ? 'pgsql' : 'sqlite'),
            'schema_enabled' => $this->config->enabled,
        ]));
    }

    public function createForm(ServerRequestInterface $request): Response
    {
        $tables = $this->introspector->tables();
        $tableNames = array_map(static fn($t): string => $t->name, $tables);

        return Response::html($this->renderHtml('schema/create', 'Create Table', [
            'tables' => $tableNames,
            'capabilities' => $this->capabilities->toArray(),
            'driver' => $this->capabilities->supportsNativeEnum() ? 'mysql' : ($this->capabilities->supportsTransactionalDdl() ? 'pgsql' : 'sqlite'),
            'schema_enabled' => $this->config->enabled,
        ]));
    }

    public function view(ServerRequestInterface $request, string $table): Response
    {
        $columns = $this->introspector->columns($table);
        $pk = $this->introspector->primaryKey($table);

        $columnData = array_map(
            static fn($col): array => [
                'name' => $col->name,
                'type' => $col->type,
                'nullable' => $col->nullable,
                'primaryKey' => $col->isPrimaryKey,
                'default' => $col->default,
            ],
            $columns,
        );

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json([
                'table' => $table,
                'columns' => $columnData,
                'primaryKey' => $pk,
                'capabilities' => $this->capabilities->toArray(),
            ]);
        }

        return Response::html($this->renderHtml('schema/view', "Table: $table", [
            'table' => $table,
            'columns' => $columnData,
            'primaryKey' => $pk,
            'capabilities' => $this->capabilities->toArray(),
            'driver' => $this->capabilities->supportsNativeEnum() ? 'mysql' : ($this->capabilities->supportsTransactionalDdl() ? 'pgsql' : 'sqlite'),
            'schema_enabled' => $this->config->enabled,
        ]));
    }

    public function changelog(ServerRequestInterface $request): Response
    {
        $entries = $this->changeLog->recent();

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
            ],
            $entries,
        );

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(['entries' => $entryData]);
        }

        return Response::html($this->renderHtml('schema/changelog', 'Schema Change Log', [
            'entries' => $entryData,
            'schema_enabled' => $this->config->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderHtml(string $content, string $title, array $templateData): string
    {
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
