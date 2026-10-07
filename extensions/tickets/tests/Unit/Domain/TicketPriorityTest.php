<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\TicketPriority;

use function count;

final class TicketPriorityTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        $expected = ['low', 'normal', 'high', 'urgent', 'critical'];
        $actual = array_map(static fn(TicketPriority $p) => $p->value, TicketPriority::cases());

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function labelReturnsHumanReadableString(): void
    {
        self::assertSame('Low', TicketPriority::Low->label());
        self::assertSame('Normal', TicketPriority::Normal->label());
        self::assertSame('High', TicketPriority::High->label());
        self::assertSame('Urgent', TicketPriority::Urgent->label());
        self::assertSame('Critical', TicketPriority::Critical->label());
    }

    #[Test]
    public function weightIsStrictlyIncreasing(): void
    {
        $priorities = TicketPriority::cases();

        for ($i = 1; $i < count($priorities); $i++) {
            self::assertGreaterThan(
                $priorities[$i - 1]->weight(),
                $priorities[$i]->weight(),
                "{$priorities[$i]->value} should have higher weight than {$priorities[$i - 1]->value}",
            );
        }
    }

    #[Test]
    public function badgeVariantReturnsDangerForUrgentAndCritical(): void
    {
        self::assertSame('danger', TicketPriority::Urgent->badgeVariant());
        self::assertSame('danger', TicketPriority::Critical->badgeVariant());
    }

    #[Test]
    public function fromStringCreatesCorrectCase(): void
    {
        self::assertSame(TicketPriority::High, TicketPriority::from('high'));
        self::assertSame(TicketPriority::Normal, TicketPriority::from('normal'));
    }
}
