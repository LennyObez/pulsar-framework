<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a key rotation operation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RotationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $success,
        public string $kid,
        public KeyType $keyType,
        public DateTimeImmutable $rotatedAt,
        public ?string $previousKid,
        public string $reason,
        public array $metadata = [],
    ) {}

    #[NoDiscard]
    public static function success(
        string $kid,
        KeyType $keyType,
        ?string $previousKid = null,
        string $reason = 'scheduled',
    ): self {
        return new self(
            success: true,
            kid: $kid,
            keyType: $keyType,
            rotatedAt: new DateTimeImmutable(),
            previousKid: $previousKid,
            reason: $reason,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function failure(
        string $kid,
        KeyType $keyType,
        string $reason,
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            kid: $kid,
            keyType: $keyType,
            rotatedAt: new DateTimeImmutable(),
            previousKid: null,
            reason: $reason,
            metadata: $metadata,
        );
    }

    /**
     * @return array{success: bool, kid: string, key_type: string, rotated_at: string, previous_kid: ?string, reason: string, metadata: array<string, mixed>}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'kid' => $this->kid,
            'key_type' => $this->keyType->value,
            'rotated_at' => $this->rotatedAt->format(DateTimeImmutable::ATOM),
            'previous_kid' => $this->previousKid,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
