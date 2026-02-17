<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\ContentModeration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\Internal\InMemoryModerationLog;

#[CoversClass(InMemoryModerationLog::class)]
final class ModerationLogTest extends TestCase
{
    private InMemoryModerationLog $log;

    protected function setUp(): void
    {
        $this->log = new InMemoryModerationLog();
    }

    #[Test]
    public function recordAndFindByIdReturnsDecision(): void
    {
        $decision = $this->createDecision('dec-1', 'post-1', 'remove');

        $this->log->record($decision);

        $found = $this->log->findById('dec-1');
        self::assertNotNull($found);
        self::assertSame('dec-1', $found->id);
        self::assertSame('post-1', $found->contentId);
    }

    #[Test]
    public function findByIdReturnsNullForMissingDecision(): void
    {
        self::assertNull($this->log->findById('nonexistent'));
    }

    #[Test]
    public function findByContentIdReturnsMatchingDecisions(): void
    {
        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove'));
        $this->log->record($this->createDecision('dec-2', 'post-1', 'restrict'));
        $this->log->record($this->createDecision('dec-3', 'post-2', 'label'));

        $found = $this->log->findByContentId('post-1');

        self::assertCount(2, $found);
        self::assertSame('dec-1', $found[0]->id);
        self::assertSame('dec-2', $found[1]->id);
    }

    #[Test]
    public function findByContentIdReturnsEmptyForNoMatches(): void
    {
        self::assertSame([], $this->log->findByContentId('nonexistent'));
    }

    #[Test]
    public function findByDateRangeReturnsDecisionsInRange(): void
    {
        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove', '2026-01-01T00:00:00+00:00'));
        $this->log->record($this->createDecision('dec-2', 'post-2', 'restrict', '2026-06-15T12:00:00+00:00'));
        $this->log->record($this->createDecision('dec-3', 'post-3', 'label', '2026-12-31T23:59:59+00:00'));

        $from = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $to = new DateTimeImmutable('2026-06-30T23:59:59+00:00');

        $found = $this->log->findByDateRange($from, $to);

        self::assertCount(1, $found);
        self::assertSame('dec-2', $found[0]->id);
    }

    #[Test]
    public function countByDecisionTypeAggregatesCorrectly(): void
    {
        $from = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $to = new DateTimeImmutable('2026-12-31T23:59:59+00:00');

        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove', '2026-03-01T00:00:00+00:00'));
        $this->log->record($this->createDecision('dec-2', 'post-2', 'remove', '2026-03-02T00:00:00+00:00'));
        $this->log->record($this->createDecision('dec-3', 'post-3', 'restrict', '2026-03-03T00:00:00+00:00'));

        $counts = $this->log->countByDecisionType($from, $to);

        self::assertSame(2, $counts['remove']);
        self::assertSame(1, $counts['restrict']);
    }

    #[Test]
    public function countByDetectionMethodAggregatesCorrectly(): void
    {
        $from = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $to = new DateTimeImmutable('2026-12-31T23:59:59+00:00');

        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove', '2026-03-01T00:00:00+00:00', 'automated'));
        $this->log->record($this->createDecision('dec-2', 'post-2', 'remove', '2026-03-02T00:00:00+00:00', 'automated'));
        $this->log->record($this->createDecision('dec-3', 'post-3', 'restrict', '2026-03-03T00:00:00+00:00', 'trusted_flagger'));

        $counts = $this->log->countByDetectionMethod($from, $to);

        self::assertSame(2, $counts['automated']);
        self::assertSame(1, $counts['trusted_flagger']);
    }

    #[Test]
    public function countReturnsZeroForEmptyLog(): void
    {
        self::assertSame(0, $this->log->count());
    }

    #[Test]
    public function countReturnsTotalDecisionCount(): void
    {
        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove'));
        $this->log->record($this->createDecision('dec-2', 'post-2', 'restrict'));

        self::assertSame(2, $this->log->count());
    }

    #[Test]
    public function recordOverwritesDecisionWithSameId(): void
    {
        $this->log->record($this->createDecision('dec-1', 'post-1', 'remove'));
        $this->log->record($this->createDecision('dec-1', 'post-1', 'restrict'));

        self::assertSame(1, $this->log->count());

        $found = $this->log->findById('dec-1');
        self::assertNotNull($found);
        self::assertSame('restrict', $found->decision);
    }

    private function createDecision(
        string $id,
        string $contentId,
        string $decision,
        string $decidedAt = '2026-03-15T10:00:00+00:00',
        string $detectionMethod = 'human',
    ): ModerationDecision {
        return new ModerationDecision(
            id: $id,
            contentId: $contentId,
            contentType: 'post',
            decision: $decision,
            reason: 'Test reason',
            policyId: 'policy-test',
            detectionMethod: $detectionMethod,
            decidedAt: new DateTimeImmutable($decidedAt),
            appealUrl: 'https://example.com/appeal/' . $id,
        );
    }
}
