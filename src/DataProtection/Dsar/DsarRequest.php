<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents a Data Subject Access Request (DSAR).
 *
 * Under GDPR Article 15, data subjects have the right to obtain a copy
 * of their personal data within 30 days. This DTO tracks the request
 * through its lifecycle from submission to fulfillment.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DsarRequest
{
    public function __construct(
        public string $id,
        public string $subjectId,
        public string $email,
        public DsarStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $deadline,
        public ?DateTimeImmutable $completedAt = null,
        public ?string $packagePath = null,
        public ?string $verificationToken = null,
    ) {}

    /**
     * Check if the request deadline has passed.
     */
    #[NoDiscard]
    public function isOverdue(): bool
    {
        return $this->status !== DsarStatus::Completed
            && $this->status !== DsarStatus::Rejected
            && $this->deadline < new DateTimeImmutable();
    }

    /**
     * Calculate remaining days until the deadline.
     */
    #[NoDiscard]
    public function remainingDays(): int
    {
        $now = new DateTimeImmutable();
        $diff = $now->diff($this->deadline);

        $days = $diff->days !== false ? $diff->days : 0;

        return $diff->invert === 1 ? -$days : $days;
    }
}
