<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Provides scoped access to environment variables for extensions.
 *
 * Implementations may filter or deny access based on the extension's
 * trust tier and granted capabilities.
 */
#[Api(since: '1.0.0')]
interface EnvironmentInterface
{
    /**
     * Get an environment variable value, or null if not set or access is denied.
     */
    public function get(string $key): ?string;

    /**
     * Check if an environment variable is accessible.
     */
    public function has(string $key): bool;
}
