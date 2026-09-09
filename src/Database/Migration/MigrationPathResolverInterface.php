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
 * @api
 */
#[Api(since: '1.0.0')]
interface MigrationPathResolverInterface
{
    /**
     * Resolve all migration paths in priority order.
     *
     * Returns the framework's own migration paths first, then the project migration
     * path, then extension migration paths in the order extensions were registered.
     *
     * Each entry is keyed by the NAME of the source that ships it — `core:Auth`,
     * `project`, `ext:pulsar/cms`. The name is what
     * {@see MigrationRepository::__construct()} qualifies sequential versions with, so
     * it must identify the source and must be identical on every host: it becomes part
     * of the version string recorded in the migrations table. Iterating the returned
     * array by value still yields the directory paths, which is all a consumer that
     * only scans directories needs.
     *
     * @return array<string, string> Source name => absolute directory path
     */
    public function resolve(): array;
}
