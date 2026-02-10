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
        private readonly ListResourceHandler $handler,
        private readonly ResourceRegistryInterface $registry,
        private readonly AdminConfig $config,
    ) {}

    public function list(Request $request, string $resource): Response
    {
        $filters = [];
        /** @var mixed $rawFilters */
        $rawFilters = $request->query('filters');
        if (is_string($rawFilters)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawFilters, true) ?? [];
            $filters = $decoded;
        }

        /** @var array<string, string> $sort */
        $sort = [];
        $sortField = $request->query('sort_field');
        $sortDir = $request->query('sort_dir');
        if (is_string($sortField) && $sortField !== '') {
            $sort[$sortField] = is_string($sortDir) ? $sortDir : 'asc';
        }

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
        ob_start();
        $title = $resourceDef->pluralLabel();
        $content = 'resource-list';
        $templateData = [
            'resource' => $resourceDef,
            'result' => $result,
            'filters' => $filters,
            'sort' => $sort,
            'schema_enabled' => $this->config->schema->enabled,
        ];
        include __DIR__ . '/../View/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }
}
