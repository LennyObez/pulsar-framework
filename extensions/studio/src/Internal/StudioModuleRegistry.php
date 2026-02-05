<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Contracts\StudioModuleRegistryInterface;
use Pulsar\Extension\Studio\Exception\StudioException;

use function array_values;
use function preg_match;
use function usort;

/**
 * In-memory registry of Studio UI modules.
 *
 * Validates module IDs (charset + uniqueness) and route prefix collisions
 * on registration. Returns modules sorted by navOrder().
 */
#[Internal]
final class StudioModuleRegistry implements StudioModuleRegistryInterface
{
    /** @var array<string, StudioModuleInterface> */
    private array $modules = [];

    /** @var array<string, string> Route prefix -> module ID mapping for collision detection */
    private array $prefixes = [];

    #[Override]
    public function register(StudioModuleInterface $module): void
    {
        $moduleId = $module->moduleId();

        // Validate charset: [a-z0-9_-]+
        if (preg_match('/^[a-z0-9_-]+$/', $moduleId) !== 1) {
            throw StudioException::invalidModuleId($moduleId);
        }

        // Validate uniqueness
        if (isset($this->modules[$moduleId])) {
            throw StudioException::duplicateModuleId($moduleId);
        }

        // Validate route prefix collision
        $routePrefix = $module->routePrefix();

        if (isset($this->prefixes[$routePrefix])) {
            throw StudioException::routePrefixCollision(
                $moduleId,
                $routePrefix,
                $this->prefixes[$routePrefix],
            );
        }

        $this->modules[$moduleId] = $module;
        $this->prefixes[$routePrefix] = $moduleId;
    }

    /**
     * @return list<StudioModuleInterface>
     */
    #[Override]
    public function modules(): array
    {
        $sorted = array_values($this->modules);

        usort($sorted, static fn(StudioModuleInterface $a, StudioModuleInterface $b): int => $a->navOrder() <=> $b->navOrder());

        return $sorted;
    }

    #[Override]
    public function get(string $moduleId): ?StudioModuleInterface
    {
        return $this->modules[$moduleId] ?? null;
    }
}
