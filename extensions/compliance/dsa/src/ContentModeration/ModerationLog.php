<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\ContentModeration;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable log of all content moderation actions per DSA Article 15.
 *
 * Platforms must maintain a log of all moderation decisions and make
 * this data available for transparency reporting. The log is append-only
 * to ensure auditability and regulatory compliance.
 */
#[Api(since: '1.0.0')]
abstract class ModerationLog
{
    /**
     * Record a moderation decision in the log.
     */
    abstract public function record(ModerationDecision $decision): void;

    /**
     * Retrieve a decision by its unique identifier.
     */
    #[NoDiscard]
    abstract public function findById(string $id): ?ModerationDecision;

    /**
     * Retrieve all decisions for a specific content item.
     *
     * @return list<ModerationDecision>
     */
    #[NoDiscard]
    abstract public function findByContentId(string $contentId): array;

    /**
     * Retrieve all decisions within a date range for transparency reporting.
     *
     * @return list<ModerationDecision>
     */
    #[NoDiscard]
    abstract public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Count decisions by decision type within a date range.
     *
     * @return array<string, int> Map of decision type to count
     */
    #[NoDiscard]
    abstract public function countByDecisionType(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Count decisions by detection method within a date range.
     *
     * @return array<string, int> Map of detection method to count
     */
    #[NoDiscard]
    abstract public function countByDetectionMethod(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Return the total number of decisions recorded in the log.
     */
    #[NoDiscard]
    abstract public function count(): int;
}
