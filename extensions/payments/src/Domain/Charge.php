<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable charge record.
 */
#[Api(since: '1.0.0')]
final readonly class Charge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $intentId,
        public Money $amount,
        public ChargeStatus $status,
        public string $provider,
        public DateTimeImmutable $createdAt,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}
}
