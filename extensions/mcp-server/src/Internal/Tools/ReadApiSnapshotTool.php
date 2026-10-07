<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Introspection\ProjectMetadataService;

use function array_slice;
use function count;
use function is_int;
use function is_string;
use function json_encode;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadApiSnapshotTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.api.snapshot';
    }

    public function description(): string
    {
        return 'Returns the public API snapshot (classes marked with #[Api])';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'Filter by exact class name'],
                'namespacePrefix' => ['type' => 'string', 'description' => 'Filter by namespace prefix'],
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
                'classes' => ['type' => 'object'],
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
        $classes = $snapshot->apiSnapshot->classes;

        // Filter by exact class name
        /** @var mixed $classFilter */
        $classFilter = $params['class'] ?? null;
        if (is_string($classFilter) && $classFilter !== '') {
            $classes = isset($classes[$classFilter])
                ? [$classFilter => $classes[$classFilter]]
                : [];
        }

        // Filter by namespace prefix
        /** @var mixed $nsPrefix */
        $nsPrefix = $params['namespacePrefix'] ?? null;
        if (is_string($nsPrefix) && $nsPrefix !== '') {
            $classes = array_filter(
                $classes,
                static fn(string $fqcn): bool => str_starts_with($fqcn, $nsPrefix),
                ARRAY_FILTER_USE_KEY,
            );
        }

        // Pagination
        /** @var int $limitVal */
        $limitVal = isset($params['limit']) && is_int($params['limit']) ? $params['limit'] : 100;
        $limit = max(1, $limitVal);
        /** @var mixed $cursor */
        $cursor = $params['cursor'] ?? null;
        $allKeys = array_keys($classes);
        $totalCount = count($allKeys);

        $offset = 0;
        if (is_string($cursor) && $cursor !== '') {
            $pos = array_search($cursor, $allKeys, true);
            $offset = $pos !== false ? (int) $pos : 0;
        }

        $pageKeys = array_slice($allKeys, $offset, $limit);
        $pageClasses = [];
        foreach ($pageKeys as $key) {
            $pageClasses[$key] = $classes[$key];
        }

        $nextCursor = ($offset + $limit < $totalCount) ? $allKeys[$offset + $limit] : null;

        $structured = [
            'classes' => $pageClasses,
            'totalCount' => $totalCount,
            'nextCursor' => $nextCursor,
        ];

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
