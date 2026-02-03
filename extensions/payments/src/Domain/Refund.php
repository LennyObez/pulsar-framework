<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable refund record.
 */
#[Api]
final readonly class Refund
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $chargeId,
        public Money $amount,
        public RefundStatus $status,
        public string $provider,
        public DateTimeImmutable $createdAt,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}
}
