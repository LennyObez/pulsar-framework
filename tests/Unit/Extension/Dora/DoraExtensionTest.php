<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\DoraExtension;
use Pulsar\Extension\Dora\DoraServiceProvider;

#[CoversClass(DoraExtension::class)]
final class DoraExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarDora(): void
    {
        $ext = new DoraExtension();

        self::assertSame('pulsar/dora', $ext->name());
    }

    #[Test]
    public function providersIncludesServiceProvider(): void
    {
        $ext = new DoraExtension();
        $providers = $ext->providers();

        self::assertCount(1, $providers);
        self::assertSame(DoraServiceProvider::class, $providers[0]);
    }
}
