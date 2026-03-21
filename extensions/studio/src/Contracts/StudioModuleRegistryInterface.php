<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Studio\Exception\StudioException;

/**
 * Registry of Studio UI modules.
 *
 * Manages module registration, lookup, and enumeration.
 * The registry validates module IDs and route prefixes on registration.
 * @api
 */
#[Api(since: '1.0.0')]
interface StudioModuleRegistryInterface
{
    /**
     * Register a Studio module.
     *
     * @throws StudioException When the module ID is invalid, duplicate, or route prefix collides
     */
    public function register(StudioModuleInterface $module): void;

    /**
     * Get all registered modules, sorted by nav order.
     *
     * @return list<StudioModuleInterface>
     */
    public function modules(): array;

    /**
     * Get a module by its ID, or null if not registered.
     */
    public function get(string $moduleId): ?StudioModuleInterface;
}
