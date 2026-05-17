<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function array_values;

/**
 * Schedules automatic key rotations based on configured intervals.
 *
 * Maintains rotation schedules per key type and determines which keys
 * are due for rotation. Supports configurable grace periods where both
 * old and new keys remain valid for decryption.
 *
 * Addresses PCI-DSS Req 3.6 (key management lifecycle).
 * @api
 */
#[Api(since: '1.0.0')]
final class KeyRotationScheduler
{
    /** @var array<string, RotationSchedule> kid => schedule */
    private array $schedules = [];

    public function __construct(
        private readonly KeyInventory $inventory,
        private readonly int $defaultGracePeriodSeconds = 86400,
    ) {}

    /**
     * Schedule a key for automatic rotation.
     */
    public function schedule(string $kid, int $intervalSeconds, ?int $gracePeriodSeconds = null): void
    {
        $this->schedules[$kid] = new RotationSchedule(
            kid: $kid,
            intervalSeconds: $intervalSeconds,
            gracePeriodSeconds: $gracePeriodSeconds ?? $this->defaultGracePeriodSeconds,
        );
    }

    /**
     * Remove a key from the rotation schedule.
     */
    public function unschedule(string $kid): void
    {
        unset($this->schedules[$kid]);
    }

    /**
     * Get all keys that are currently due for rotation.
     *
     * @return list<RotationSchedule>
     */
    #[NoDiscard]
    public function dueForRotation(?DateTimeImmutable $now = null): array
    {
        $due = [];

        foreach ($this->schedules as $schedule) {
            $entry = $this->inventory->find($schedule->kid);

            if ($entry === null || !$entry->active) {
                continue;
            }

            if ($entry->isDueForRotation($now)) {
                $due[] = $schedule;
            }
        }

        return $due;
    }

    /**
     * Check if a specific key is in its grace period (recently rotated,
     * both old and new keys should be accepted for decryption).
     */
    #[NoDiscard]
    public function isInGracePeriod(string $kid, ?DateTimeImmutable $now = null): bool
    {
        $schedule = $this->schedules[$kid] ?? null;

        if ($schedule === null) {
            return false;
        }

        $entry = $this->inventory->find($kid);

        if ($entry === null || $entry->lastRotatedAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $entry->lastRotatedAt->getTimestamp();

        return $elapsed < $schedule->gracePeriodSeconds;
    }

    /**
     * Get the schedule for a specific key.
     */
    #[NoDiscard]
    public function getSchedule(string $kid): ?RotationSchedule
    {
        return $this->schedules[$kid] ?? null;
    }

    /**
     * Get all registered schedules.
     *
     * @return list<RotationSchedule>
     */
    #[NoDiscard]
    public function allSchedules(): array
    {
        return array_values($this->schedules);
    }
}
