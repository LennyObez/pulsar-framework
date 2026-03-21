<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;

/**
 * Registry for user-defined compliance controls.
 *
 * Organizations can register custom controls beyond the built-in regulatory
 * framework mappings. Custom controls appear in verification reports alongside
 * framework controls.
 * @api
 */
#[Api(since: '1.0.0')]
final class CustomControlRegistry
{
    /** @var array<string, CustomControl> */
    private array $controls = [];

    /**
     * Register a custom control.
     */
    public function register(CustomControl $control): void
    {
        $this->controls[$control->id] = $control;
    }

    /**
     * Retrieve a custom control by ID.
     */
    #[NoDiscard]
    public function get(string $id): ?CustomControl
    {
        return $this->controls[$id] ?? null;
    }

    /**
     * Check if a custom control exists.
     */
    #[NoDiscard]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->controls);
    }

    /**
     * Return all registered custom controls.
     *
     * @return list<CustomControl>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->controls);
    }

    /**
     * Return controls in a specific category.
     *
     * @return list<CustomControl>
     */
    #[NoDiscard]
    public function byCategory(string $category): array
    {
        return array_values(array_filter(
            $this->controls,
            static fn(CustomControl $c): bool => $c->category === $category,
        ));
    }

    /**
     * Execute all custom controls and return results.
     *
     * @return list<CheckResult>
     */
    public function verifyAll(): array
    {
        $results = [];

        foreach ($this->controls as $control) {
            $results[] = ($control->verifier)();
        }

        return $results;
    }

    /**
     * Execute a single custom control.
     */
    public function verify(string $id): ?CheckResult
    {
        $control = $this->controls[$id] ?? null;

        if ($control === null) {
            return null;
        }

        return ($control->verifier)();
    }

    /**
     * Return the number of registered custom controls.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->controls);
    }
}
