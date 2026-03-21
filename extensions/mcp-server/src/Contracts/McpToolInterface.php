<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolResult;

/**
 * Contract for an executable MCP tool.
 *
 * Implementations provide schema metadata for discovery and an execute
 * method for invocation via the MCP protocol.
 * @api
 */
#[Api(since: '1.0.0')]
interface McpToolInterface
{
    /**
     * Unique tool identifier used in tools/call requests.
     */
    public function name(): string;

    /**
     * Human-readable description shown to MCP clients.
     */
    public function description(): string;

    /**
     * JSON Schema describing accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * JSON Schema describing the structured output shape.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * Tool category for permission classification.
     */
    public function category(): ToolCategory;

    /**
     * Execute the tool with the given parameters.
     *
     * @param array<string, mixed> $params Validated input parameters
     */
    public function execute(array $params): ToolResult;
}
