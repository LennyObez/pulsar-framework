<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Introspection\ProjectMetadataService;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function is_string;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadContainerBindingsTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.container.bindings';
    }

    public function description(): string
    {
        return 'Returns container binding keys (FQCN-like patterns only)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => ['type' => 'string', 'description' => 'Substring match filter'],
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
                'bindings' => ['type' => 'array'],
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
        $bindings = $snapshot->architectureMap->bindings;

        // Filter by substring
        $filter = $params['filter'] ?? null;
        if (is_string($filter) && $filter !== '') {
            $bindings = array_filter(
                $bindings,
                static fn(string $b): bool => str_contains($b, $filter),
            );
            $bindings = array_values($bindings);
        }

        // Pagination
        $limit = (int) ($params['limit'] ?? 100);
        $totalCount = count($bindings);
        $cursor = $params['cursor'] ?? null;
        $offset = is_string($cursor) && $cursor !== '' ? (int) $cursor : 0;

        $page = array_slice($bindings, $offset, $limit);
        $nextCursor = ($offset + $limit < $totalCount) ? (string) ($offset + $limit) : null;

        $structured = [
            'bindings' => $page,
            'totalCount' => $totalCount,
            'nextCursor' => $nextCursor,
        ];

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
