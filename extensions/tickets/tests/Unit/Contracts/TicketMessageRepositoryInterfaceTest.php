<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketMessage;

#[CoversNothing]
final class TicketMessageRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnMessagesByTicket(): void
    {
        $msg = TicketMessage::create('msg-1', 'ticket-1', 'user-1', 'Alice', 'Hello');

        $stub = $this->createStub(TicketMessageRepositoryInterface::class);
        $stub->method('findByTicket')->willReturn([$msg]);

        $messages = $stub->findByTicket('ticket-1');
        self::assertCount(1, $messages);
        self::assertSame('Hello', $messages[0]->body);
    }

    #[Test]
    public function stubCanReturnEmptyListForTicketWithNoMessages(): void
    {
        $stub = $this->createStub(TicketMessageRepositoryInterface::class);
        $stub->method('findByTicket')->willReturn([]);

        self::assertCount(0, $stub->findByTicket('empty-ticket'));
    }

    #[Test]
    public function stubCanCountMessages(): void
    {
        $stub = $this->createStub(TicketMessageRepositoryInterface::class);
        $stub->method('countByTicket')->willReturn(5);

        self::assertSame(5, $stub->countByTicket('ticket-1'));
    }

    #[Test]
    public function stubCanCountZeroMessages(): void
    {
        $stub = $this->createStub(TicketMessageRepositoryInterface::class);
        $stub->method('countByTicket')->willReturn(0);

        self::assertSame(0, $stub->countByTicket('ticket-no-msgs'));
    }
}
