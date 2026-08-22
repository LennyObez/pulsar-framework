<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketMessageRepository;

#[CoversClass(DbTicketMessageRepository::class)]
final class DbTicketMessageRepositoryTest extends TestCase
{
    #[Test]
    public function findByTicketReturnsPublicMessages(): void
    {
        $row = new Row([
            'id' => 'msg-1',
            'ticket_id' => 'ticket-1',
            'author_id' => 'user-1',
            'author_name' => 'Alice',
            'body' => 'Hello, I need help',
            'is_internal' => false,
            'attachments' => '["file1.pdf"]',
            'created_at' => '2026-03-15T10:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketMessageRepository($connection);
        $messages = $repo->findByTicket('ticket-1', includeInternal: false);

        self::assertCount(1, $messages);
        self::assertSame('msg-1', $messages[0]->id);
        self::assertSame('Hello, I need help', $messages[0]->body);
        self::assertFalse($messages[0]->isInternal);
        self::assertSame(['file1.pdf'], $messages[0]->attachments);
    }

    #[Test]
    public function findByTicketIncludingInternalMessages(): void
    {
        $row1 = new Row([
            'id' => 'msg-1', 'ticket_id' => 'ticket-1', 'author_id' => 'user-1',
            'author_name' => 'Alice', 'body' => 'Public', 'is_internal' => false,
            'attachments' => '[]', 'created_at' => '2026-03-15T10:00:00+00:00',
        ]);
        $row2 = new Row([
            'id' => 'msg-2', 'ticket_id' => 'ticket-1', 'author_id' => 'admin-1',
            'author_name' => 'Admin', 'body' => 'Internal note', 'is_internal' => '1',
            'attachments' => null, 'created_at' => '2026-03-15T11:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row1, $row2]));

        $repo = new DbTicketMessageRepository($connection);
        $messages = $repo->findByTicket('ticket-1', includeInternal: true);

        self::assertCount(2, $messages);
    }

    #[Test]
    public function findByTicketReturnsEmptyWhenNoMessages(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbTicketMessageRepository($connection);
        $messages = $repo->findByTicket('empty-ticket');

        self::assertCount(0, $messages);
    }

    #[Test]
    public function findByTicketHandlesNullAuthorId(): void
    {
        $row = new Row([
            'id' => 'msg-sys', 'ticket_id' => 'ticket-1', 'author_id' => null,
            'author_name' => 'System', 'body' => 'Automated message', 'is_internal' => false,
            'attachments' => '[]', 'created_at' => '2026-03-15T09:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketMessageRepository($connection);
        $messages = $repo->findByTicket('ticket-1');

        self::assertNull($messages[0]->authorId);
        self::assertSame('System', $messages[0]->authorName);
    }

    #[Test]
    public function saveExecutesInsertStatement(): void
    {
        $message = TicketMessage::create('msg-new', 'ticket-1', 'user-1', 'Alice', 'New message', ['doc.pdf']);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $repo = new DbTicketMessageRepository($connection);
        $repo->save($message);

        self::assertSame('msg-new', $message->id);
    }

    #[Test]
    public function countByTicketReturnsCount(): void
    {
        $row = new Row(['cnt' => 7]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketMessageRepository($connection);

        self::assertSame(7, $repo->countByTicket('ticket-1'));
    }

    #[Test]
    public function countByTicketReturnsZeroWhenEmpty(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbTicketMessageRepository($connection);

        self::assertSame(0, $repo->countByTicket('empty-ticket'));
    }

    #[Test]
    public function hydrateHandlesEmptyStringAttachments(): void
    {
        $row = new Row([
            'id' => 'msg-empty-att', 'ticket_id' => 'ticket-1', 'author_id' => 'user-1',
            'author_name' => 'Bob', 'body' => 'No attachments', 'is_internal' => false,
            'attachments' => '', 'created_at' => '2026-03-15T10:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketMessageRepository($connection);
        $messages = $repo->findByTicket('ticket-1');

        self::assertSame([], $messages[0]->attachments);
    }
}
