<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Http\Message\Response;

use function is_string;
use function str_contains;

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

    public function list(ServerRequestInterface $request, string $resource): Response
    {
        $queryParams = $request->getQueryParams();

        $filters = [];
        $rawFilters = $queryParams['filters'] ?? null;
        if (is_string($rawFilters)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawFilters, true) ?? [];
            $filters = $decoded;
        }

        $sortField = $queryParams['sort_field'] ?? null;
        $sortDir = $queryParams['sort_dir'] ?? null;
        /** @var array<string, string> $sort */
        $sort = (is_string($sortField) && $sortField !== '')
            ? [$sortField => is_string($sortDir) ? $sortDir : 'asc']
            : [];

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = max(1, (int) ($queryParams['per_page'] ?? 25));

        $result = $this->handler->execute(new ListResourceRequest(
            resourceName: $resource,
            filters: $filters,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        ));

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
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
