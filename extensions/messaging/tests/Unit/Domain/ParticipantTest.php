<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\Participant;

#[CoversClass(Participant::class)]
final class ParticipantTest extends TestCase
{
    public function testConstructSetsAllProperties(): void
    {
        $joined = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $lastRead = new DateTimeImmutable('2026-03-21T10:00:00+00:00');

        $participant = new Participant(
            userId: 'user-1',
            conversationId: 'conv-1',
            joinedAt: $joined,
            lastReadAt: $lastRead,
        );

        self::assertSame('user-1', $participant->userId);
        self::assertSame('conv-1', $participant->conversationId);
        self::assertSame($joined, $participant->joinedAt);
        self::assertSame($lastRead, $participant->lastReadAt);
    }

    public function testHasReadUpToReturnsTrueForOlderMessage(): void
    {
        $participant = new Participant(
            userId: 'user-1',
            conversationId: 'conv-1',
            joinedAt: new DateTimeImmutable(),
            lastReadAt: new DateTimeImmutable('2026-03-21T12:00:00+00:00'),
        );

        $messageTime = new DateTimeImmutable('2026-03-21T11:00:00+00:00');
        self::assertTrue($participant->hasReadUpTo($messageTime));
    }

    public function testHasReadUpToReturnsFalseForNewerMessage(): void
    {
        $participant = new Participant(
            userId: 'user-1',
            conversationId: 'conv-1',
            joinedAt: new DateTimeImmutable(),
            lastReadAt: new DateTimeImmutable('2026-03-21T10:00:00+00:00'),
        );

        $messageTime = new DateTimeImmutable('2026-03-21T12:00:00+00:00');
        self::assertFalse($participant->hasReadUpTo($messageTime));
    }

    public function testHasReadUpToReturnsFalseWhenNeverRead(): void
    {
        $participant = new Participant(
            userId: 'user-1',
            conversationId: 'conv-1',
            joinedAt: new DateTimeImmutable(),
            lastReadAt: null,
        );

        self::assertFalse($participant->hasReadUpTo(new DateTimeImmutable()));
    }

    public function testHasReadUpToReturnsTrueForSameTimestamp(): void
    {
        $timestamp = new DateTimeImmutable('2026-03-21T12:00:00+00:00');

        $participant = new Participant(
            userId: 'user-1',
            conversationId: 'conv-1',
            joinedAt: new DateTimeImmutable(),
            lastReadAt: $timestamp,
        );

        self::assertTrue($participant->hasReadUpTo($timestamp));
    }
}
