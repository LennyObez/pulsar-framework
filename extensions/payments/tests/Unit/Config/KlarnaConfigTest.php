<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\KlarnaConfig;

final class KlarnaConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = KlarnaConfig::fromArray([
            'enabled' => true,
            'region' => 'na',
            'pay_later_enabled' => false,
            'pay_now_enabled' => true,
            'slice_it_enabled' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('na', $config->region);
        self::assertFalse($config->payLaterEnabled);
        self::assertTrue($config->payNowEnabled);
        self::assertFalse($config->sliceItEnabled);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = KlarnaConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('eu', $config->region);
        self::assertTrue($config->payLaterEnabled);
        self::assertTrue($config->payNowEnabled);
        self::assertTrue($config->sliceItEnabled);
    }

    #[Test]
    #[DataProvider('invalidRegionProvider')]
    public function fromArrayFallsBackToEuForInvalidRegion(mixed $region): void
    {
        $config = KlarnaConfig::fromArray(['region' => $region]);

        self::assertSame('eu', $config->region);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRegionProvider(): iterable
    {
        yield 'invalid string' => ['invalid'];
        yield 'empty string' => [''];
        yield 'integer' => [42];
        yield 'null' => [null];
    }

    #[Test]
    public function fromArrayAcceptsAllValidRegions(): void
    {
        foreach (['eu', 'na', 'oc'] as $region) {
            $config = KlarnaConfig::fromArray(['region' => $region]);
            self::assertSame($region, $config->region, "Region '$region' should be accepted");
        }
    }
}
