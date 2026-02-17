<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\IdealConfig;

final class IdealConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = IdealConfig::fromArray([
            'enabled' => true,
            'provider' => 'mollie',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('mollie', $config->provider);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = IdealConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('stripe', $config->provider);
    }

    #[Test]
    public function fromArrayHandlesNonStringProvider(): void
    {
        $config = IdealConfig::fromArray([
            'provider' => 123,
        ]);

        self::assertSame('stripe', $config->provider);
    }
}
