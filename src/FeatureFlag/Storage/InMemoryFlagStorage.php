<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag\Storage;

use NoDiscard;
use Override;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagStorageInterface;

/**
 * In-memory feature flag storage.
 */
final class InMemoryFlagStorage implements FlagStorageInterface
{
    /** @var array<string, FlagDefinition> */
    private array $flags = [];

    #[Override]
    #[NoDiscard]
    public function get(string $name): ?FlagDefinition
    {
        return $this->flags[$name] ?? null;
    }

    #[Override]
    public function all(): array
    {
        return $this->flags;
    }

    #[Override]
    public function has(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    #[Override]
    public function set(FlagDefinition $flag): void
    {
        $this->flags[$flag->name] = $flag;
    }

    #[Override]
    public function remove(string $name): void
    {
        unset($this->flags[$name]);
    }
}
