<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\DataActExtension;
use Pulsar\Extension\DataAct\DataActServiceProvider;

#[CoversClass(DataActExtension::class)]
final class DataActExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarDataAct(): void
    {
        $extension = new DataActExtension();

        self::assertSame('pulsar/data-act', $extension->name());
    }

    #[Test]
    public function providersIncludesServiceProvider(): void
    {
        $extension = new DataActExtension();

        $providers = $extension->providers();

        self::assertContains(DataActServiceProvider::class, $providers);
        self::assertCount(1, $providers);
    }
}
