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
use function is_int;
use function is_string;
use function json_encode;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadCommandsTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.commands.list';
    }

    public function description(): string
    {
        return 'List all registered console commands with arguments and options';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'namespace' => ['type' => 'string', 'description' => 'Filter by command namespace (e.g. make)'],
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
                'commands' => ['type' => 'array'],
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
        $commands = $snapshot->commandReference->commands;

        // Filter by namespace
        /** @var mixed $nsFilter */
        $nsFilter = $params['namespace'] ?? null;
        if (is_string($nsFilter) && $nsFilter !== '') {
            $prefix = $nsFilter . ':';
            $commands = array_filter(
                $commands,
                static fn($c): bool => str_starts_with($c->name, $prefix),
            );
            $commands = array_values($commands);
        }

        // Pagination
        /** @var int $limit */
        $limit = isset($params['limit']) && is_int($params['limit']) ? $params['limit'] : 100;
        $totalCount = count($commands);
        /** @var mixed $cursor */
        $cursor = $params['cursor'] ?? null;
        $offset = is_string($cursor) && $cursor !== '' ? (int) $cursor : 0;

        $page = array_slice($commands, $offset, $limit);
        $nextCursor = ($offset + $limit < $totalCount) ? (string) ($offset + $limit) : null;

        $structured = [
            'commands' => array_map(static fn($c): array => $c->toArray(), $page),
            'totalCount' => $totalCount,
            'nextCursor' => $nextCursor,
        ];

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
