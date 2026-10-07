<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\TicketMessage;

final class TicketMessageTest extends TestCase
{
    #[Test]
    public function createMakesPublicMessage(): void
    {
        $message = TicketMessage::create(
            id: 'msg-1',
            ticketId: 'ticket-1',
            authorId: 'user-1',
            authorName: 'John Doe',
            body: 'Hello, I need help.',
        );

        self::assertSame('msg-1', $message->id);
        self::assertSame('ticket-1', $message->ticketId);
        self::assertSame('user-1', $message->authorId);
        self::assertSame('John Doe', $message->authorName);
        self::assertSame('Hello, I need help.', $message->body);
        self::assertFalse($message->isInternal);
        self::assertSame([], $message->attachments);
    }

    #[Test]
    public function createWithAttachments(): void
    {
        $message = TicketMessage::create(
            id: 'msg-2',
            ticketId: 'ticket-1',
            authorId: 'user-1',
            authorName: 'John',
            body: 'See attached.',
            attachments: ['file1.pdf', 'screenshot.png'],
        );

        self::assertSame(['file1.pdf', 'screenshot.png'], $message->attachments);
    }

    #[Test]
    public function createWithNullAuthorId(): void
    {
        $message = TicketMessage::create(
            id: 'msg-3',
            ticketId: 'ticket-1',
            authorId: null,
            authorName: 'Guest User',
            body: 'Guest message.',
        );

        self::assertNull($message->authorId);
    }

    #[Test]
    public function createInternalMakesInternalNote(): void
    {
        $note = TicketMessage::createInternal(
            id: 'note-1',
            ticketId: 'ticket-1',
            authorId: 'agent-1',
            authorName: 'Agent Smith',
            body: 'Internal note: escalate to manager.',
        );

        self::assertTrue($note->isInternal);
        self::assertSame('agent-1', $note->authorId);
        self::assertSame('Internal note: escalate to manager.', $note->body);
    }

    #[Test]
    public function createInternalWithAttachments(): void
    {
        $note = TicketMessage::createInternal(
            id: 'note-2',
            ticketId: 'ticket-1',
            authorId: 'agent-1',
            authorName: 'Agent',
            body: 'Note with files.',
            attachments: ['logs.txt'],
        );

        self::assertSame(['logs.txt'], $note->attachments);
        self::assertTrue($note->isInternal);
    }

    #[Test]
    public function createdAtIsSet(): void
    {
        $message = TicketMessage::create(
            id: 'msg-4',
            ticketId: 'ticket-1',
            authorId: null,
            authorName: 'Test',
            body: 'Test.',
        );

        self::assertNotNull($message->createdAt);
    }
}
