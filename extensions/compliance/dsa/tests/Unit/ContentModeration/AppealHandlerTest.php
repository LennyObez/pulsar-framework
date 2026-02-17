<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\ContentModeration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\ContentModeration\AppealHandler;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\Internal\InMemoryModerationLog;

#[CoversClass(AppealHandler::class)]
final class AppealHandlerTest extends TestCase
{
    private InMemoryModerationLog $log;
    private AppealHandler $handler;

    protected function setUp(): void
    {
        $this->log = new InMemoryModerationLog();
        $this->handler = new AppealHandler($this->log);
    }

    #[Test]
    public function submitReturnsAppealRecordForExistingDecision(): void
    {
        $decision = new ModerationDecision(
            id: 'dec-1',
            contentId: 'post-1',
            contentType: 'post',
            decision: 'remove',
            reason: 'Hate speech',
            policyId: 'policy-1',
            detectionMethod: 'human',
            decidedAt: new DateTimeImmutable('2026-03-10T10:00:00+00:00'),
            appealUrl: 'https://example.com/appeal/dec-1',
        );
        $this->log->record($decision);

        $submittedAt = new DateTimeImmutable('2026-03-15T14:30:00+00:00');
        $result = $this->handler->submit(
            'appeal-1',
            'dec-1',
            'I disagree with this decision',
            'user-42',
            $submittedAt,
        );

        self::assertNotNull($result);
        self::assertSame('appeal-1', $result['appeal_id']);
        self::assertSame('dec-1', $result['decision_id']);
        self::assertSame('pending_review', $result['status']);
        self::assertSame('2026-03-15T14:30:00+00:00', $result['submitted_at']);
        self::assertSame('remove', $result['original_decision']);
    }

    #[Test]
    public function submitReturnsNullForNonExistentDecision(): void
    {
        $result = $this->handler->submit(
            'appeal-2',
            'nonexistent-dec',
            'Appeal reason',
            'user-42',
            new DateTimeImmutable(),
        );

        self::assertNull($result);
    }

    #[Test]
    public function resolveReturnsResolutionRecord(): void
    {
        $resolvedAt = new DateTimeImmutable('2026-03-20T09:00:00+00:00');
        $result = $this->handler->resolve(
            'appeal-1',
            'overturned',
            'reviewer-7',
            $resolvedAt,
        );

        self::assertSame('appeal-1', $result['appeal_id']);
        self::assertSame('overturned', $result['outcome']);
        self::assertSame('2026-03-20T09:00:00+00:00', $result['resolved_at']);
        self::assertSame('reviewer-7', $result['reviewer_id']);
    }

    #[Test]
    public function resolveWithUpheldOutcome(): void
    {
        $result = $this->handler->resolve(
            'appeal-3',
            'upheld',
            'reviewer-12',
            new DateTimeImmutable('2026-04-01T08:00:00+00:00'),
        );

        self::assertSame('upheld', $result['outcome']);
    }
}
