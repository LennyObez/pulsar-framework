<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

/**
 * Determines whether a tool invocation is permitted.
 *
 * Checks tool-level allow/deny lists and category-based restrictions.
 */
#[Api(since: '1.0.0')]
interface ToolPermissionCheckerInterface
{
    /**
     * Assert that the named tool is allowed to execute.
     *
     * @throws McpSecurityException When the tool is not permitted
     */
    public function assertAllowed(string $toolName): void;

    /**
     * Check if the named tool is allowed without throwing.
     */
    public function isAllowed(string $toolName): bool;
}
