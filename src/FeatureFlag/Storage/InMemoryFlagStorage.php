<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag\Storage;

use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagStorageInterface;

/**
 * In-memory feature flag storage.
 */
final class InMemoryFlagStorage implements FlagStorageInterface
{
    /** @var array<string, FlagDefinition> */
    private array $flags = [];

    public function get(string $name): ?FlagDefinition
    {
        return $this->flags[$name] ?? null;
    }

    public function all(): array
    {
        return $this->flags;
    }

    public function has(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    public function set(FlagDefinition $flag): void
    {
        $this->flags[$flag->name] = $flag;
    }

    public function remove(string $name): void
    {
        unset($this->flags[$name]);
    }
}
