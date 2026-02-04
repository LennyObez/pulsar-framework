<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpToolInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;

use function array_map;
use function array_values;

/**
 * In-memory registry of available MCP tools.
 */
#[Internal]
final class McpToolRegistry implements McpToolRegistryInterface
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    public function register(McpToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * @return list<ToolDefinition>
     */
    public function list(): array
    {
        return array_values(array_map(
            static fn(McpToolInterface $tool): ToolDefinition => new ToolDefinition(
                name: $tool->name(),
                description: $tool->description(),
                inputSchema: $tool->inputSchema(),
                outputSchema: $tool->outputSchema(),
                category: $tool->category(),
            ),
            $this->tools,
        ));
    }

    public function get(string $name): McpToolInterface
    {
        if (!$this->has($name)) {
            throw McpException::toolNotFound($name);
        }

        return $this->tools[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function call(string $name, array $params): ToolResult
    {
        $tool = $this->get($name);

        return $tool->execute($params);
    }
}
