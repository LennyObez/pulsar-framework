<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ListResourceResult;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Http\Message\Response;

use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function str_contains;

/**
 * Controller for listing resource records.
 */
#[Internal]
final readonly class ResourceListController
{
    use RendersAdminLayout;

    public function __construct(
        private ListResourceHandler $handler,
        private ResourceRegistryInterface $registry,
        private AdminConfig $config,
    ) {}

    public function list(ServerRequestInterface $request, string $resource): Response
    {
        $queryParams = $request->getQueryParams();

        $filters = [];
        /** @var mixed $rawFilters */
        $rawFilters = $queryParams['filters'] ?? null;
        if (is_string($rawFilters)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawFilters, true) ?? [];
            $filters = $decoded;
        }

        /** @var mixed $sortField */
        $sortField = $queryParams['sort_field'] ?? null;
        /** @var mixed $sortDir */
        $sortDir = $queryParams['sort_dir'] ?? null;
        /** @var array<string, string> $sort */
        $sort = (is_string($sortField) && $sortField !== '')
            ? [$sortField => is_string($sortDir) ? $sortDir : 'asc']
            : [];

        /** @var mixed $pageRaw */
        $pageRaw = $queryParams['page'] ?? 1;
        $page = max(1, (is_int($pageRaw) || is_string($pageRaw)) && is_numeric($pageRaw) ? (int) $pageRaw : 1);
        $defaultPerPage = $this->config->pagination->defaultPerPage;
        /** @var mixed $perPageRaw */
        $perPageRaw = $queryParams['per_page'] ?? $defaultPerPage;
        $perPage = min(
            $this->config->pagination->maxPerPage,
            max(1, (is_int($perPageRaw) || is_string($perPageRaw)) && is_numeric($perPageRaw) ? (int) $perPageRaw : $defaultPerPage),
        );

        try {
            $result = $this->handler->execute(new ListResourceRequest(
                resourceName: $resource,
                filters: $filters,
                sort: $sort,
                page: $page,
                perPage: $perPage,
            ));
        } catch (DatabaseException $e) {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return Response::json([
                    'error' => 'Query failed for this resource.',
                    'message' => $e->getMessage(),
                ], 422);
            }

            $resourceDef = $this->registry->get($resource);

            return Response::html($this->renderAdminView($resourceDef->pluralLabel(), 'resource-list', [
                'resource' => $resourceDef,
                'result' => new ListResourceResult(data: [], total: 0, page: 1, perPage: $perPage, totalPages: 1),
                'filters' => $filters,
                'sort' => $sort,
                'schema_enabled' => $this->config->schema->enabled,
                'error' => 'Unable to query this resource: ' . $e->getMessage(),
            ]));
        }

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

        return Response::html($this->renderAdminView($resourceDef->pluralLabel(), 'resource-list', [
            'resource' => $resourceDef,
            'result' => $result,
            'filters' => $filters,
            'sort' => $sort,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

}
