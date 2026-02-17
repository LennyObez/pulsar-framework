<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Messaging;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Messaging\Conversation;
use Pulsar\Extension\Forum\Messaging\PrivateMessage;

final class ConversationTest extends TestCase
{
    #[Test]
    public function hasParticipantReturnsTrueForMember(): void
    {
        $conversation = new Conversation(
            id: 'conv-1',
            participantIds: ['user-a', 'user-b', 'user-c'],
        );

        self::assertTrue($conversation->hasParticipant('user-a'));
        self::assertTrue($conversation->hasParticipant('user-b'));
    }

    #[Test]
    public function hasParticipantReturnsFalseForNonMember(): void
    {
        $conversation = new Conversation(
            id: 'conv-1',
            participantIds: ['user-a', 'user-b'],
        );

        self::assertFalse($conversation->hasParticipant('user-x'));
    }

    #[Test]
    public function conversationStoresAllProperties(): void
    {
        $created = new DateTimeImmutable('2026-03-01');
        $lastMsg = new DateTimeImmutable('2026-03-20');

        $conversation = new Conversation(
            id: 'conv-1',
            participantIds: ['user-a', 'user-b'],
            subject: 'About the project',
            messageCount: 15,
            createdAt: $created,
            lastMessageAt: $lastMsg,
        );

        self::assertSame('conv-1', $conversation->id);
        self::assertSame('About the project', $conversation->subject);
        self::assertSame(15, $conversation->messageCount);
        self::assertSame($created, $conversation->createdAt);
        self::assertSame($lastMsg, $conversation->lastMessageAt);
    }

    #[Test]
    public function privateMessageIsReadDetection(): void
    {
        $unread = new PrivateMessage(
            id: 'msg-1',
            conversationId: 'conv-1',
            senderId: 'user-a',
            body: 'Hello!',
        );

        $read = new PrivateMessage(
            id: 'msg-2',
            conversationId: 'conv-1',
            senderId: 'user-b',
            body: 'Hi!',
            readAt: new DateTimeImmutable(),
        );

        self::assertFalse($unread->isRead());
        self::assertTrue($read->isRead());
    }

    #[Test]
    public function privateMessageStoresAllProperties(): void
    {
        $sentAt = new DateTimeImmutable('2026-03-20 14:30:00');

        $message = new PrivateMessage(
            id: 'msg-1',
            conversationId: 'conv-1',
            senderId: 'user-a',
            body: 'Test message body',
            sentAt: $sentAt,
            isDeleted: false,
        );

        self::assertSame('msg-1', $message->id);
        self::assertSame('conv-1', $message->conversationId);
        self::assertSame('user-a', $message->senderId);
        self::assertSame('Test message body', $message->body);
        self::assertSame($sentAt, $message->sentAt);
        self::assertFalse($message->isDeleted);
    }

    #[Test]
    public function privateMessageDefaultsToUnreadAndNotDeleted(): void
    {
        $message = new PrivateMessage(
            id: 'msg-2',
            conversationId: 'conv-1',
            senderId: 'user-b',
            body: 'Content',
        );

        self::assertNull($message->readAt);
        self::assertFalse($message->isDeleted);
        self::assertFalse($message->isRead());
    }

    #[Test]
    public function conversationDefaultsToEmptySubject(): void
    {
        $conversation = new Conversation(
            id: 'conv-2',
            participantIds: ['user-a'],
        );

        self::assertSame('', $conversation->subject);
        self::assertSame(0, $conversation->messageCount);
    }
}
