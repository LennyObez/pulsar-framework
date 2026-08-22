<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FunnelStepResult;

#[CoversClass(FunnelStepResult::class)]
final class FunnelStepResultTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $result = new FunnelStepResult(
            position: 2,
            name: 'Signup Form',
            visitors: 450,
            dropOffRate: 25.0,
            conversionRate: 75.0,
        );

        self::assertSame(2, $result->position);
        self::assertSame('Signup Form', $result->name);
        self::assertSame(450, $result->visitors);
        self::assertSame(25.0, $result->dropOffRate);
        self::assertSame(75.0, $result->conversionRate);
    }

    #[Test]
    public function zeroVisitorsStep(): void
    {
        $result = new FunnelStepResult(5, 'Final', 0, 100.0, 0.0);

        self::assertSame(0, $result->visitors);
        self::assertSame(100.0, $result->dropOffRate);
        self::assertSame(0.0, $result->conversionRate);
    }
}
