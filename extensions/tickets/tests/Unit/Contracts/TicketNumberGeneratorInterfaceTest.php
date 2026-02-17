<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;

#[CoversClass(TicketNumberGeneratorInterface::class)]
final class TicketNumberGeneratorInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnTicketNumber(): void
    {
        $stub = $this->createStub(TicketNumberGeneratorInterface::class);
        $stub->method('next')->willReturn('TKT-2026-000001');

        self::assertSame('TKT-2026-000001', $stub->next());
    }

    #[Test]
    public function stubCanReturnSequentialNumbers(): void
    {
        $stub = $this->createStub(TicketNumberGeneratorInterface::class);
        $stub->method('next')->willReturnOnConsecutiveCalls(
            'TKT-2026-000001',
            'TKT-2026-000002',
            'TKT-2026-000003',
        );

        self::assertSame('TKT-2026-000001', $stub->next());
        self::assertSame('TKT-2026-000002', $stub->next());
        self::assertSame('TKT-2026-000003', $stub->next());
    }
}
