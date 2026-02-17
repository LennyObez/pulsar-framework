<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;

/**
 * Resolves all migration directories to scan.
 *
 * Collects paths from the project configuration and from registered
 * extensions that declare migrations in their manifest. Consumers
 * (CLI commands, dev servers, wiring classes) inject this service
 * instead of manually assembling paths.
 */
#[Api(since: '1.0.0')]
interface MigrationPathResolverInterface
{
    /**
     * Resolve all migration paths in priority order.
     *
     * Returns the project migration path first, followed by extension
     * migration paths in the order extensions were registered.
     *
     * @return list<string> Absolute directory paths
     */
    public function resolve(): array;
}
