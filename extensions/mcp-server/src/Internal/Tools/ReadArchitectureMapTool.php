<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Introspection\ProjectMetadataService;
use stdClass;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal]
final readonly class ReadArchitectureMapTool implements McpToolInterface
{
    public function __construct(
        private ProjectMetadataService $metadataService,
    ) {}

    public function name(): string
    {
        return 'pulsar.architecture.map';
    }

    public function description(): string
    {
        return 'Returns the architecture map: registered extensions and container bindings';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new stdClass(),
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'extensions' => ['type' => 'array'],
                'bindings' => ['type' => 'array'],
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
        $structured = $snapshot->architectureMap->toArray();

        return ToolResult::success(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
        );
    }
}
