<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FunnelResult;
use Pulsar\Extension\Analytics\Domain\FunnelStepResult;

#[CoversClass(FunnelResult::class)]
final class FunnelResultTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $steps = [
            new FunnelStepResult(1, 'Landing', 1000, 0.0, 100.0),
            new FunnelStepResult(2, 'Signup', 600, 40.0, 60.0),
            new FunnelStepResult(3, 'Purchase', 200, 66.7, 33.3),
        ];

        $result = new FunnelResult(
            funnelId: 'f-1',
            steps: $steps,
            overallConversionRate: 20.0,
        );

        self::assertSame('f-1', $result->funnelId);
        self::assertCount(3, $result->steps);
        self::assertSame(20.0, $result->overallConversionRate);
    }

    #[Test]
    public function emptyStepsResultsInZeroConversion(): void
    {
        $result = new FunnelResult('f-2', [], 0.0);

        self::assertSame([], $result->steps);
        self::assertSame(0.0, $result->overallConversionRate);
    }
}
