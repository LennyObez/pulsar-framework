<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\AttributionResult;

final class AttributionResultTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $result = new AttributionResult(
            source: 'organic',
            conversions: 42,
        );

        self::assertSame('organic', $result->source);
        self::assertSame(42, $result->conversions);
        self::assertSame(0.0, $result->revenue);
        self::assertSame(1.0, $result->weight);
    }

    #[Test]
    public function construction_fully_specified(): void
    {
        $result = new AttributionResult(
            source: 'paid_search',
            conversions: 100,
            revenue: 5500.50,
            weight: 0.75,
        );

        self::assertSame('paid_search', $result->source);
        self::assertSame(100, $result->conversions);
        self::assertSame(5500.50, $result->revenue);
        self::assertSame(0.75, $result->weight);
    }
}
