<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function array_map;
use function count;

/**
 * Admin schema builder HTML page controller.
 */
#[Internal]
final readonly class SchemaController
{
    public function __construct(
        private readonly DatabaseIntrospector $introspector,
        private readonly SchemaCapabilities $capabilities,
        private readonly AdminSchemaConfig $config,
        private readonly SchemaChangeLogStoreInterface $changeLog,
    ) {}

    public function list(Request $request): Response
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

        if ($request->wantsJson()) {
            return Response::json([
                'tables' => $tableData,
                'capabilities' => $this->capabilities->toArray(),
            ]);
        }

        return Response::html($this->renderHtml('schema/list', 'Database', [
            'tables' => $tableData,
            'capabilities' => $this->capabilities->toArray(),
            'driver' => $this->capabilities->supportsNativeEnum() ? 'mysql' : ($this->capabilities->supportsTransactionalDdl() ? 'pgsql' : 'sqlite'),
        ]));
    }

    public function createForm(Request $request): Response
    {
        $tables = $this->introspector->tables();
        $tableNames = array_map(static fn($t): string => $t->name, $tables);

        return Response::html($this->renderHtml('schema/create', 'Create Table', [
            'tables' => $tableNames,
            'capabilities' => $this->capabilities->toArray(),
            'driver' => $this->capabilities->supportsNativeEnum() ? 'mysql' : ($this->capabilities->supportsTransactionalDdl() ? 'pgsql' : 'sqlite'),
        ]));
    }

    public function view(Request $request, string $table): Response
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

        if ($request->wantsJson()) {
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
        ]));
    }

    public function changelog(Request $request): Response
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

        if ($request->wantsJson()) {
            return Response::json(['entries' => $entryData]);
        }

        return Response::html($this->renderHtml('schema/changelog', 'Schema Change Log', [
            'entries' => $entryData,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderHtml(string $content, string $title, array $templateData): string
    {
        $templateData['schema_enabled'] = $this->config->enabled;
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
