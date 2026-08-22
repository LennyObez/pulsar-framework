<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Introspection\ProjectMetadataService;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadRoutesTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.routes.list';
    }

    public function description(): string
    {
        return 'List all registered routes with method, path, handler, and middleware';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'method' => ['type' => 'string', 'description' => 'Filter by HTTP method (e.g. GET)'],
                'path' => ['type' => 'string', 'description' => 'Filter by path substring'],
                'cursor' => ['type' => 'string', 'description' => 'Pagination cursor'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 100)'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'routes' => ['type' => 'array'],
                'totalCount' => ['type' => 'integer'],
                'nextCursor' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function execute(array $params): ToolResult
    {
        $snapshot = $this->metadataService->snapshot();
        $routes = $snapshot->routeMap->routes;

        // Filter by method
        /** @var mixed $methodFilter */
        $methodFilter = $params['method'] ?? null;
        if (is_string($methodFilter) && $methodFilter !== '') {
            $methodUpper = strtoupper($methodFilter);
            $routes = array_filter(
                $routes,
                static fn($r): bool => in_array($methodUpper, $r->methods, true),
            );
            $routes = array_values($routes);
        }

        // Filter by path
        /** @var mixed $pathFilter */
        $pathFilter = $params['path'] ?? null;
        if (is_string($pathFilter) && $pathFilter !== '') {
            $routes = array_filter(
                $routes,
                static fn($r): bool => str_contains($r->path, $pathFilter),
            );
            $routes = array_values($routes);
        }

        // Pagination
        /** @var int $limit */
        $limit = isset($params['limit']) && is_int($params['limit']) ? $params['limit'] : 100;
        $totalCount = count($routes);
        /** @var mixed $cursor */
        $cursor = $params['cursor'] ?? null;
        $offset = is_string($cursor) && $cursor !== '' ? (int) $cursor : 0;

        $page = array_slice($routes, $offset, $limit);
        $nextCursor = ($offset + $limit < $totalCount) ? (string) ($offset + $limit) : null;

        $structured = [
            'routes' => array_map(static fn($r): array => $r->toArray(), $page),
            'totalCount' => $totalCount,
            'nextCursor' => $nextCursor,
        ];

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
