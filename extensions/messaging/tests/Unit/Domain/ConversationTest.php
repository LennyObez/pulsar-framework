<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Domain\Conversation;
use Pulsar\Extension\Messaging\Domain\ConversationType;

#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase
{
    public function testConstructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();
        $conversation = new Conversation(
            id: 'conv-1',
            type: ConversationType::Group,
            participantIds: ['user-a', 'user-b', 'user-c'],
            title: 'Project Chat',
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame('conv-1', $conversation->id);
        self::assertSame(ConversationType::Group, $conversation->type);
        self::assertSame(['user-a', 'user-b', 'user-c'], $conversation->participantIds);
        self::assertSame('Project Chat', $conversation->title);
        self::assertSame($now, $conversation->createdAt);
        self::assertSame($now, $conversation->updatedAt);
    }

    public function testHasParticipantReturnsTrue(): void
    {
        $conversation = $this->createConversation(['alice', 'bob']);

        self::assertTrue($conversation->hasParticipant('alice'));
        self::assertTrue($conversation->hasParticipant('bob'));
    }

    public function testHasParticipantReturnsFalseForNonMember(): void
    {
        $conversation = $this->createConversation(['alice', 'bob']);

        self::assertFalse($conversation->hasParticipant('charlie'));
    }

    public function testParticipantCount(): void
    {
        $conversation = $this->createConversation(['a', 'b', 'c']);

        self::assertSame(3, $conversation->participantCount());
    }

    public function testNullTitle(): void
    {
        $conversation = $this->createConversation(['a', 'b'], null);

        self::assertNull($conversation->title);
    }

    /**
     * @param list<string> $participants
     */
    private function createConversation(array $participants, ?string $title = 'Test'): Conversation
    {
        $now = new DateTimeImmutable();

        return new Conversation(
            id: 'conv-test',
            type: ConversationType::Direct,
            participantIds: $participants,
            title: $title,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
