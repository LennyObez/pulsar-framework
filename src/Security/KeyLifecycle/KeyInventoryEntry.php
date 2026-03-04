<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable record of a cryptographic key in the inventory.
 *
 * Tracks key metadata without storing actual key material.
 */
#[Api(since: '1.0.0')]
final readonly class KeyInventoryEntry
{
    public function __construct(
        public string $kid,
        public KeyType $type,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastRotatedAt,
        public int $rotationIntervalSeconds,
        public bool $active,
        public string $algorithm,
        public string $context,
    ) {}

    /**
     * Check if this key is due for rotation.
     */
    #[NoDiscard]
    public function isDueForRotation(?DateTimeImmutable $now = null): bool
    {
        if ($this->rotationIntervalSeconds <= 0) {
            return false;
        }

        $referenceTime = $this->lastRotatedAt ?? $this->createdAt;
        $now ??= new DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $referenceTime->getTimestamp();

        return $elapsed >= $this->rotationIntervalSeconds;
    }

    /**
     * Seconds remaining until rotation is due.
     *
     * Returns 0 if already due or rotation is disabled.
     */
    #[NoDiscard]
    public function secondsUntilRotation(?DateTimeImmutable $now = null): int
    {
        if ($this->rotationIntervalSeconds <= 0) {
            return 0;
        }

        $referenceTime = $this->lastRotatedAt ?? $this->createdAt;
        $now ??= new DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $referenceTime->getTimestamp();
        $remaining = $this->rotationIntervalSeconds - $elapsed;

        return max(0, $remaining);
    }

    /**
     * @return array{kid: string, type: string, created_at: string, last_rotated_at: ?string, rotation_interval_seconds: int, active: bool, algorithm: string, context: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'kid' => $this->kid,
            'type' => $this->type->value,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'last_rotated_at' => $this->lastRotatedAt?->format(DateTimeImmutable::ATOM),
            'rotation_interval_seconds' => $this->rotationIntervalSeconds,
            'active' => $this->active,
            'algorithm' => $this->algorithm,
            'context' => $this->context,
        ];
    }
}
