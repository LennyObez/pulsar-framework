<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\AnalyticsExtension;
use Pulsar\Extension\Analytics\AnalyticsServiceProvider;

final class AnalyticsExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarAnalytics(): void
    {
        $ext = new AnalyticsExtension();

        self::assertSame('pulsar/analytics', $ext->name());
    }

    #[Test]
    public function providersReturnsServiceProvider(): void
    {
        $ext = new AnalyticsExtension();

        self::assertSame([AnalyticsServiceProvider::class], $ext->providers());
    }
}
