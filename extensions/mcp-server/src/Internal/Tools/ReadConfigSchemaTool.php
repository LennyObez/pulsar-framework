<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Introspection\ProjectMetadataService;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadConfigSchemaTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.config.schema';
    }

    public function description(): string
    {
        return 'Returns config DTO schemas (property names, types, scrubbed defaults)';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Filter by config class name'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schemas' => ['type' => 'array'],
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
        $schemas = $snapshot->configSchema->schemas;

        $nameFilter = $params['name'] ?? null;
        if (is_string($nameFilter) && $nameFilter !== '') {
            $schemas = array_filter(
                $schemas,
                static fn($entry): bool => str_contains($entry->className, $nameFilter),
            );
            $schemas = array_values($schemas);
        }

        $structured = [
            'schemas' => array_map(
                static fn($e): array => $e->toArray(),
                $schemas,
            ),
        ];

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
