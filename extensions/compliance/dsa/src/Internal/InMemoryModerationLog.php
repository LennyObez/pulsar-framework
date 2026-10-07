<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Internal;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\ContentModeration\ModerationLog;

use function array_filter;
use function array_values;
use function count;

/**
 * In-memory implementation of ModerationLog for development and testing.
 *
 * Production deployments should replace this with a persistent
 * implementation backed by a database.
 */
#[Internal(reason: 'Default in-memory implementation; override with persistent storage')]
final class InMemoryModerationLog extends ModerationLog
{
    /** @var array<string, ModerationDecision> */
    private array $decisions = [];

    public function record(ModerationDecision $decision): void
    {
        $this->decisions[$decision->id] = $decision;
    }

    #[NoDiscard]
    public function findById(string $id): ?ModerationDecision
    {
        return $this->decisions[$id] ?? null;
    }

    /**
     * @return list<ModerationDecision>
     */
    #[NoDiscard]
    public function findByContentId(string $contentId): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn(ModerationDecision $d): bool => $d->contentId === $contentId,
        ));
    }

    /**
     * @return list<ModerationDecision>
     */
    #[NoDiscard]
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn(ModerationDecision $d): bool => $d->decidedAt >= $from && $d->decidedAt <= $to,
        ));
    }

    /**
     * @return array<string, int>
     */
    #[NoDiscard]
    public function countByDecisionType(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = [];
        $inRange = $this->findByDateRange($from, $to);

        foreach ($inRange as $decision) {
            $counts[$decision->decision] = ($counts[$decision->decision] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    #[NoDiscard]
    public function countByDetectionMethod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = [];
        $inRange = $this->findByDateRange($from, $to);

        foreach ($inRange as $decision) {
            $counts[$decision->detectionMethod] = ($counts[$decision->detectionMethod] ?? 0) + 1;
        }

        return $counts;
    }

    #[NoDiscard]
    public function count(): int
    {
        return count($this->decisions);
    }
}
