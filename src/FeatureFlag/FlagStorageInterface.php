<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

/**
 * Interface for feature flag storage backends.
 */
#[Api]
interface FlagStorageInterface
{
    /**
     * Get a flag definition by name.
     */
    public function get(string $name): ?FlagDefinition;

    /**
     * Get all stored flag definitions.
     *
     * @return array<string, FlagDefinition>
     */
    public function all(): array;

    /**
     * Check if a flag exists.
     */
    public function has(string $name): bool;

    /**
     * Store or update a flag definition.
     */
    public function set(FlagDefinition $flag): void;

    /**
     * Remove a flag definition.
     */
    public function remove(string $name): void;
}
