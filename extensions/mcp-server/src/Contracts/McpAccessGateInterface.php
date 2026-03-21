<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

/**
 * Access gate for MCP operations.
 *
 * Enforces environment-level, path-level, and concurrency constraints.
 * @api
 */
#[Api(since: '1.0.0')]
interface McpAccessGateInterface
{
    /**
     * Assert that the current environment allows MCP operations.
     *
     * @throws McpSecurityException When the environment blocks MCP access
     */
    public function assertEnvironmentAllowed(): void;

    /**
     * Assert that accessing the given path is permitted.
     *
     * @throws McpSecurityException When the path is not in the allowlist
     */
    public function assertPathAllowed(string $path): void;

    /**
     * Assert that a concurrent action slot is available.
     *
     * @throws McpSecurityException When the concurrency limit is reached
     */
    public function assertConcurrencyAllowed(): void;

    /**
     * Release a concurrent action slot after completion.
     */
    public function releaseConcurrencySlot(): void;
}
