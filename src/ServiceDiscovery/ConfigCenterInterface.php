<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use Pulsar\Api\Api;

/**
 * Centralized configuration center for distributed service settings.
 *
 * Provides key-value configuration with optional namespacing,
 * versioning, and integrity verification via the Integrity module.
 */
#[Api(since: '1.0.0')]
interface ConfigCenterInterface
{
    /**
     * Get a configuration value by key within a namespace.
     */
    public function get(string $namespace, string $key): ?string;

    /**
     * Set a configuration value.
     */
    public function set(string $namespace, string $key, string $value): void;

    /**
     * Delete a configuration key.
     */
    public function delete(string $namespace, string $key): bool;

    /**
     * Get all configuration entries for a namespace.
     *
     * @return array<string, string>
     */
    public function all(string $namespace): array;

    /**
     * Check whether a configuration key exists.
     */
    public function has(string $namespace, string $key): bool;

    /**
     * List all known namespaces.
     *
     * @return list<string>
     */
    public function namespaces(): array;
}
