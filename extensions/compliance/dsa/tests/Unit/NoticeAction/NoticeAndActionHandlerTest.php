<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\NoticeAction;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\Internal\InMemoryModerationLog;
use Pulsar\Extension\Dsa\Internal\InMemoryTrustedFlaggerRegistry;
use Pulsar\Extension\Dsa\NoticeAction\IllegalContentNotice;
use Pulsar\Extension\Dsa\NoticeAction\NoticeAndActionHandler;

#[CoversClass(NoticeAndActionHandler::class)]
final class NoticeAndActionHandlerTest extends TestCase
{
    private InMemoryModerationLog $log;
    private InMemoryTrustedFlaggerRegistry $registry;
    private NoticeAndActionHandler $handler;

    protected function setUp(): void
    {
        $this->log = new InMemoryModerationLog();
        $this->registry = new InMemoryTrustedFlaggerRegistry();
        $this->handler = new NoticeAndActionHandler($this->log, $this->registry);
    }

    #[Test]
    public function processNoticeReturnsAcknowledgmentForRegularNotice(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-1',
            contentId: 'post-1',
            contentUrl: 'https://example.com/post/1',
            reason: 'Illegal content',
            submitterName: 'Reporter',
            submitterEmail: 'reporter@example.com',
            submittedAt: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
            submitterId: 'user-42',
        );

        $acknowledgedAt = new DateTimeImmutable('2026-03-15T10:01:00+00:00');
        $result = $this->handler->processNotice($notice, $acknowledgedAt);

        self::assertSame('notice-1', $result['notice_id']);
        self::assertSame('acknowledged', $result['status']);
        self::assertFalse($result['priority']);
        self::assertSame('2026-03-15T10:01:00+00:00', $result['acknowledged_at']);
    }

    #[Test]
    public function processNoticeSetsPriorityForTrustedFlagger(): void
    {
        $this->registry->register('tf-1', 'Child Safety Org', 'DSC DE', 'child_safety');

        $notice = new IllegalContentNotice(
            id: 'notice-2',
            contentId: 'post-2',
            contentUrl: 'https://example.com/post/2',
            reason: 'CSAM detected',
            submitterName: 'Child Safety Org',
            submitterEmail: 'csorg@example.com',
            submittedAt: new DateTimeImmutable(),
            submitterId: 'tf-1',
        );

        $result = $this->handler->processNotice($notice, new DateTimeImmutable());

        self::assertTrue($result['priority']);
    }

    #[Test]
    public function processNoticeWithNullSubmitterIdIsNotPriority(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-3',
            contentId: 'post-3',
            contentUrl: 'https://example.com/post/3',
            reason: 'Anonymous report',
            submitterName: 'Anonymous',
            submitterEmail: 'anon@example.com',
            submittedAt: new DateTimeImmutable(),
        );

        $result = $this->handler->processNotice($notice, new DateTimeImmutable());

        self::assertFalse($result['priority']);
    }

    #[Test]
    public function actOnNoticeRecordsDecisionInLog(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-4',
            contentId: 'post-4',
            contentUrl: 'https://example.com/post/4',
            reason: 'Illegal content',
            submitterName: 'Reporter',
            submitterEmail: 'reporter@example.com',
            submittedAt: new DateTimeImmutable(),
        );

        $decision = new ModerationDecision(
            id: 'dec-1',
            contentId: 'post-4',
            contentType: 'post',
            decision: 'remove',
            reason: 'Content violates law',
            policyId: 'policy-1',
            detectionMethod: 'notice',
            decidedAt: new DateTimeImmutable(),
            appealUrl: 'https://example.com/appeal/dec-1',
        );

        $this->handler->actOnNotice($notice, $decision);

        self::assertNotNull($this->log->findById('dec-1'));
        self::assertSame(1, $this->log->count());
    }

    #[Test]
    public function dismissReturnsDismmissalRecord(): void
    {
        $notice = new IllegalContentNotice(
            id: 'notice-5',
            contentId: 'post-5',
            contentUrl: 'https://example.com/post/5',
            reason: 'Reported content',
            submitterName: 'Reporter',
            submitterEmail: 'reporter@example.com',
            submittedAt: new DateTimeImmutable(),
        );

        $dismissedAt = new DateTimeImmutable('2026-03-16T10:00:00+00:00');
        $result = $this->handler->dismiss(
            $notice,
            'Content does not violate applicable law',
            $dismissedAt,
        );

        self::assertSame('notice-5', $result['notice_id']);
        self::assertSame('dismissed', $result['status']);
        self::assertSame('2026-03-16T10:00:00+00:00', $result['dismissed_at']);
        self::assertSame('Content does not violate applicable law', $result['reason']);
    }
}
