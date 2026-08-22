<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function count;

/**
 * Tracks all cryptographic keys in use across the application.
 *
 * Provides compliance-ready inventory of key type, creation date,
 * rotation schedule, and last-rotated timestamp. Integrates with
 * the compliance verification engine for PCI-DSS Req 3.6 and
 * ISO 27001 A.8.24 reporting.
 * @api
 */
#[Api(since: '1.0.0')]
final class KeyInventory
{
    /** @var array<string, KeyInventoryEntry> kid => entry */
    private array $entries = [];

    /**
     * Register a key in the inventory.
     */
    public function register(KeyInventoryEntry $entry): void
    {
        $this->entries[$entry->kid] = $entry;
    }

    /**
     * Remove a key from the inventory.
     */
    public function deregister(string $kid): void
    {
        unset($this->entries[$kid]);
    }

    /**
     * Find a key by its identifier.
     */
    #[NoDiscard]
    public function find(string $kid): ?KeyInventoryEntry
    {
        return $this->entries[$kid] ?? null;
    }

    /**
     * Get all registered keys.
     *
     * @return list<KeyInventoryEntry>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->entries);
    }

    /**
     * Get all active keys of a specific type.
     *
     * @return list<KeyInventoryEntry>
     */
    #[NoDiscard]
    public function byType(KeyType $type): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(KeyInventoryEntry $e): bool => $e->type === $type && $e->active,
        ));
    }

    /**
     * Get all keys that are due for rotation.
     *
     * @return list<KeyInventoryEntry>
     */
    #[NoDiscard]
    public function dueForRotation(?DateTimeImmutable $now = null): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(KeyInventoryEntry $e): bool => $e->active && $e->isDueForRotation($now),
        ));
    }

    /**
     * Count of all registered keys.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Check if a key exists in the inventory.
     */
    #[NoDiscard]
    public function has(string $kid): bool
    {
        return isset($this->entries[$kid]);
    }

    /**
     * Export the full inventory for compliance reporting.
     *
     * @return list<array{kid: string, type: string, created_at: string, last_rotated_at: ?string, rotation_interval_seconds: int, active: bool, algorithm: string, context: string}>
     */
    #[NoDiscard]
    public function export(): array
    {
        return array_values(array_map(
            static fn(KeyInventoryEntry $e): array => $e->toArray(),
            $this->entries,
        ));
    }
}
