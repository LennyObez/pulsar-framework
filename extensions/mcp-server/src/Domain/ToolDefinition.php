<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Domain;

use Pulsar\Api\Api;

/**
 * Immutable description of an MCP tool for the wire protocol.
 *
 * Maps directly to the MCP tools/list response shape.
 */
#[Api(since: '1.0.0')]
final readonly class ToolDefinition
{
    /**
     * @param string $name Unique tool identifier
     * @param string $description Human-readable description
     * @param array<string, mixed> $inputSchema JSON Schema for tool parameters
     * @param array<string, mixed> $outputSchema JSON Schema for tool output
     * @param ToolCategory $category Read or action classification
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public ToolCategory $category,
    ) {}
}
