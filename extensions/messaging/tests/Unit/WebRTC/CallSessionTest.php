<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\WebRTC;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\CallStatus;
use Pulsar\Extension\Messaging\WebRTC\CallSession;

#[CoversClass(CallSession::class)]
final class CallSessionTest extends TestCase
{
    public function testConstructSetsProperties(): void
    {
        $now = new DateTimeImmutable();
        $session = new CallSession(
            id: 'call-1',
            callerId: 'alice',
            calleeId: 'bob',
            conversationId: 'conv-1',
            status: CallStatus::Pending,
            startedAt: $now,
        );

        self::assertSame('call-1', $session->id);
        self::assertSame('alice', $session->callerId);
        self::assertSame('bob', $session->calleeId);
        self::assertSame(CallStatus::Pending, $session->status);
        self::assertNull($session->answeredAt);
        self::assertNull($session->endedAt);
    }

    public function testAnswer(): void
    {
        $session = $this->createSession();
        $session->answer();

        self::assertSame(CallStatus::Active, $session->status);
        self::assertNotNull($session->answeredAt);
        self::assertTrue($session->isActive());
    }

    public function testEnd(): void
    {
        $session = $this->createSession();
        $session->answer();
        $session->end();

        self::assertSame(CallStatus::Ended, $session->status);
        self::assertNotNull($session->endedAt);
        self::assertFalse($session->isActive());
    }

    public function testReject(): void
    {
        $session = $this->createSession();
        $session->reject();

        self::assertSame(CallStatus::Rejected, $session->status);
        self::assertNotNull($session->endedAt);
    }

    public function testMarkMissed(): void
    {
        $session = $this->createSession();
        $session->markMissed();

        self::assertSame(CallStatus::Missed, $session->status);
        self::assertNotNull($session->endedAt);
    }

    public function testDurationSecondsBeforeAnswerReturnsNull(): void
    {
        $session = $this->createSession();

        self::assertNull($session->durationSeconds());
    }

    public function testDurationSecondsAfterAnswer(): void
    {
        $session = new CallSession(
            id: 'call-1',
            callerId: 'alice',
            calleeId: 'bob',
            conversationId: 'conv-1',
            status: CallStatus::Active,
            startedAt: new DateTimeImmutable('-10 seconds'),
            answeredAt: new DateTimeImmutable('-5 seconds'),
        );

        $duration = $session->durationSeconds();
        self::assertNotNull($duration);
        self::assertGreaterThanOrEqual(4, $duration);
        self::assertLessThanOrEqual(6, $duration);
    }

    public function testIsActiveReturnsFalseForPending(): void
    {
        $session = $this->createSession();

        self::assertFalse($session->isActive());
    }

    private function createSession(): CallSession
    {
        return new CallSession(
            id: 'call-test',
            callerId: 'alice',
            calleeId: 'bob',
            conversationId: 'conv-1',
            status: CallStatus::Pending,
            startedAt: new DateTimeImmutable(),
        );
    }
}
