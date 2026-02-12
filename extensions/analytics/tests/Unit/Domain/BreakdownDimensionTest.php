<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;

final class BreakdownDimensionTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('page', BreakdownDimension::Page->value);
        self::assertSame('referrer', BreakdownDimension::Referrer->value);
        self::assertSame('country', BreakdownDimension::Country->value);
        self::assertSame('browser', BreakdownDimension::Browser->value);
        self::assertSame('os', BreakdownDimension::Os->value);
        self::assertSame('device', BreakdownDimension::Device->value);
        self::assertSame('utm_source', BreakdownDimension::UtmSource->value);
        self::assertSame('utm_medium', BreakdownDimension::UtmMedium->value);
        self::assertSame('utm_campaign', BreakdownDimension::UtmCampaign->value);
    }

    #[Test]
    public function hasNineCases(): void
    {
        self::assertCount(9, BreakdownDimension::cases());
    }
}
