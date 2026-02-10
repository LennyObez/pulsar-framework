<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

use function in_array;

/**
 * Determines whether a tool invocation is permitted based on tool-level allow/deny lists
 * and category-based restrictions.
 *
 * Read tools are allowed by default unless explicitly disabled.
 * Action tools must be explicitly listed in the allowed_actions config.
 */
#[Internal]
final readonly class ToolPermissionChecker implements ToolPermissionCheckerInterface
{
    public function __construct(
        private McpToolsConfig $toolsConfig,
        private McpToolRegistryInterface $registry,
    ) {}

    public function assertAllowed(string $toolName): void
    {
        if (!$this->registry->has($toolName)) {
            throw McpSecurityException::toolNotAllowed($toolName);
        }

        $tool = $this->registry->get($toolName);

        if (in_array($toolName, $this->toolsConfig->disabledReadTools, true)) {
            throw McpSecurityException::toolNotAllowed($toolName);
        }

        if ($tool->category() === ToolCategory::Action && !in_array($toolName, $this->toolsConfig->allowedActions, true)) {
            throw McpSecurityException::toolNotAllowed($toolName);
        }
    }

    public function isAllowed(string $toolName): bool
    {
        try {
            $this->assertAllowed($toolName);

            return true;
        } catch (McpSecurityException) {
            return false;
        }
    }
}
