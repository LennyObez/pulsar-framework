<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\EscalationRule;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Event\TicketEscalated;

#[CoversClass(TicketEscalated::class)]
final class TicketEscalatedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $rule = new EscalationRule(60, 'notify_manager', 'mgr@example.com');
        $event = new TicketEscalated($ticket, $rule, 75);

        self::assertSame($ticket, $event->ticket);
        self::assertSame($rule, $event->triggeredRule);
        self::assertSame(75, $event->elapsedMinutes);
    }

    #[Test]
    public function elapsedMinutesCanBeZero(): void
    {
        $ticket = Ticket::create('t-2', 'TKT-2026-000002', 'Subject', 'Desc', 'a@b.com', 'Name');
        $rule = new EscalationRule(0, 'immediate', null);
        $event = new TicketEscalated($ticket, $rule, 0);

        self::assertSame(0, $event->elapsedMinutes);
    }
}
