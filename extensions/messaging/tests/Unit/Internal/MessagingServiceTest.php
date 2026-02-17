<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessageRepositoryInterface;
use Pulsar\Extension\Messaging\Domain\Conversation;
use Pulsar\Extension\Messaging\Domain\ConversationType;
use Pulsar\Extension\Messaging\Domain\MessageType;
use Pulsar\Extension\Messaging\Internal\Service\MessagingService;

#[CoversClass(MessagingService::class)]
final class MessagingServiceTest extends TestCase
{
    public function testCreateDirectConversation(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findDirect')->willReturn(null);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $conversation = $service->createConversation(
            ConversationType::Direct,
            ['alice', 'bob'],
        );

        self::assertSame(ConversationType::Direct, $conversation->type);
        self::assertSame(['alice', 'bob'], $conversation->participantIds);
        self::assertNotEmpty($conversation->id);
    }

    public function testCreateDirectConversationReturnsExisting(): void
    {
        $existing = new Conversation(
            id: 'existing-conv',
            type: ConversationType::Direct,
            participantIds: ['alice', 'bob'],
            title: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findDirect')->willReturn($existing);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $conversation = $service->createConversation(
            ConversationType::Direct,
            ['alice', 'bob'],
        );

        self::assertSame('existing-conv', $conversation->id);
    }

    public function testCreateDirectConversationWithWrongParticipantCountThrows(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 2 participants');

        $service->createConversation(ConversationType::Direct, ['alice', 'bob', 'charlie']);
    }

    public function testCreateConversationWithLessThan2ParticipantsThrows(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2');

        $service->createConversation(ConversationType::Group, ['alice']);
    }

    public function testCreateGroupConversation(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $conversation = $service->createConversation(
            ConversationType::Group,
            ['alice', 'bob', 'charlie'],
            'Team Chat',
        );

        self::assertSame(ConversationType::Group, $conversation->type);
        self::assertSame('Team Chat', $conversation->title);
        self::assertCount(3, $conversation->participantIds);
    }

    public function testSendMessage(): void
    {
        $conversation = new Conversation(
            id: 'conv-1',
            type: ConversationType::Direct,
            participantIds: ['alice', 'bob'],
            title: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findById')->willReturn($conversation);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $message = $service->sendMessage('conv-1', 'alice', 'encrypted-data', 'nonce-data');

        self::assertSame('conv-1', $message->conversationId);
        self::assertSame('alice', $message->senderId);
        self::assertSame('encrypted-data', $message->encryptedContent);
        self::assertSame('nonce-data', $message->nonce);
        self::assertSame(MessageType::Text, $message->type);
    }

    public function testSendMessageToNonExistentConversationThrows(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findById')->willReturn(null);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $service->sendMessage('nonexistent', 'alice', 'data', 'nonce');
    }

    public function testSendMessageByNonParticipantThrows(): void
    {
        $conversation = new Conversation(
            id: 'conv-1',
            type: ConversationType::Direct,
            participantIds: ['alice', 'bob'],
            title: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findById')->willReturn($conversation);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a participant');

        $service->sendMessage('conv-1', 'charlie', 'data', 'nonce');
    }

    public function testGetMessages(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $messageRepo = $this->createStub(MessageRepositoryInterface::class);
        $messageRepo->method('findByConversation')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 50, currentPage: 1, lastPage: 1),
        );

        $service = new MessagingService($conversationRepo, $messageRepo);
        $result = $service->getMessages('conv-1', 1, 50);

        self::assertSame(0, $result->total);
    }

    public function testMarkReadForNonExistentConversationDoesNothing(): void
    {
        $conversationRepo = $this->createStub(ConversationRepositoryInterface::class);
        $conversationRepo->method('findById')->willReturn(null);

        $messageRepo = $this->createStub(MessageRepositoryInterface::class);

        $service = new MessagingService($conversationRepo, $messageRepo);

        // Verify no exception is thrown for nonexistent conversation
        $this->expectNotToPerformAssertions();
        $service->markRead('nonexistent', 'alice');
    }
}
