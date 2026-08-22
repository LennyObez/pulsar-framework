<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\TicketEvent;

final class TicketEventTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        $expected = [
            'created', 'assigned', 'status_changed', 'priority_changed',
            'message_added', 'resolved', 'closed', 'reopened', 'escalated',
        ];

        $actual = array_map(static fn(TicketEvent $e) => $e->value, TicketEvent::cases());

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function labelReturnsHumanReadableString(): void
    {
        self::assertSame('Ticket Created', TicketEvent::Created->label());
        self::assertSame('Ticket Assigned', TicketEvent::Assigned->label());
        self::assertSame('Status Changed', TicketEvent::StatusChanged->label());
        self::assertSame('Ticket Escalated', TicketEvent::Escalated->label());
    }

    #[Test]
    public function everyEventHasNonEmptyLabel(): void
    {
        foreach (TicketEvent::cases() as $event) {
            self::assertNotEmpty($event->label(), "Event {$event->value} should have a non-empty label");
        }
    }
}
