<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;

/**
 * Registry of available MCP tools.
 *
 * Manages tool registration, lookup, and invocation.
 * @api
 */
#[Api(since: '1.0.0')]
interface McpToolRegistryInterface
{
    /**
     * Register a tool in the registry.
     */
    public function register(McpToolInterface $tool): void;

    /**
     * List all registered tool definitions.
     *
     * @return list<ToolDefinition>
     */
    public function list(): array;

    /**
     * Get a registered tool by name.
     *
     * @throws McpException When the tool is not found
     */
    public function get(string $name): McpToolInterface;

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool;

    /**
     * Execute a tool by name with the given parameters.
     *
     * @param array<string, mixed> $params Tool input parameters
     *
     * @throws McpException When the tool is not found
     */
    public function call(string $name, array $params): ToolResult;
}
