<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function is_string;

/**
 * Controller for listing resource records.
 */
#[Internal]
final readonly class ResourceListController
{
    public function __construct(
        private ListResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function list(Request $request, string $resource): Response
    {
        $filters = [];
        $rawFilters = $request->query('filters');
        if (is_string($rawFilters)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawFilters, true) ?? [];
            $filters = $decoded;
        }

        $sortField = $request->query('sort_field');
        $sortDir = $request->query('sort_dir');
        /** @var array<string, string> $sort */
        $sort = (is_string($sortField) && $sortField !== '')
            ? [$sortField => is_string($sortDir) ? $sortDir : 'asc']
            : [];

        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = max(1, (int) ($request->query('per_page') ?? 25));

        $result = $this->handler->execute(new ListResourceRequest(
            resourceName: $resource,
            filters: $filters,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        ));

        if ($request->wantsJson()) {
            return Response::json([
                'data' => $result->data,
                'total' => $result->total,
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total_pages' => $result->totalPages,
            ]);
        }

        $resourceDef = $this->registry->get($resource);

        return Response::html($this->renderView($resourceDef->pluralLabel(), [
            'resource' => $resourceDef,
            'result' => $result,
            'filters' => $filters,
            'sort' => $sort,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, array $templateData): string
    {
        $content = 'resource-list';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
