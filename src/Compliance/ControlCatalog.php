<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Mutable registry of regulatory controls.
 *
 * Controls are registered at boot time by framework mapping classes.
 * Once populated, the catalog provides filtered views by framework, status,
 * and individual lookup by control ID.
 * @api
 */
#[Api(since: '1.0.0')]
final class ControlCatalog
{
    /** @var array<string, Control> */
    private array $controls = [];

    /**
     * Register a control in the catalog.
     *
     * If a control with the same ID already exists, it is silently replaced.
     */
    public function register(Control $control): void
    {
        $this->controls[$control->id] = $control;
    }

    /**
     * Retrieve a control by its unique identifier.
     *
     * @return Control|null The control, or null if not found
     */
    #[NoDiscard]
    public function get(string $id): ?Control
    {
        return $this->controls[$id] ?? null;
    }

    /**
     * Return all registered controls keyed by ID.
     *
     * @return array<string, Control>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->controls;
    }

    /**
     * Return controls belonging to a specific compliance framework.
     *
     * @return list<Control>
     */
    #[NoDiscard]
    public function byFramework(string $framework): array
    {
        return array_values(
            array_filter(
                $this->controls,
                static fn(Control $control): bool => $control->framework === $framework,
            ),
        );
    }

    /**
     * Return controls matching a specific implementation status.
     *
     * @return list<Control>
     */
    #[NoDiscard]
    public function byStatus(ControlStatus $status): array
    {
        return array_values(
            array_filter(
                $this->controls,
                static fn(Control $control): bool => $control->status === $status,
            ),
        );
    }

    /**
     * Return the total number of registered controls.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->controls);
    }
}
