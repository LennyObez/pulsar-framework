<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;

/**
 * Registry of admin data resources.
 */
#[Api(since: '1.0.0')]
interface ResourceRegistryInterface
{
    /**
     * Register a data resource.
     *
     * @throws AdminException When the resource name is already registered
     */
    public function register(DataResourceInterface $resource): void;

    /**
     * Get a resource by name.
     *
     * @throws ResourceNotFoundException When the resource is not found
     */
    public function get(string $name): DataResourceInterface;

    /**
     * Check if a resource is registered.
     */
    public function has(string $name): bool;

    /**
     * Get all registered resources.
     *
     * @return array<string, DataResourceInterface>
     */
    public function all(): array;
}
