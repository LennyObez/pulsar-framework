<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\GeoInfo;

final class GeoInfoTest extends TestCase
{
    #[Test]
    public function constructWithCountryAndRegion(): void
    {
        $geo = new GeoInfo(countryCode: 'US', region: 'California');

        self::assertSame('US', $geo->countryCode);
        self::assertSame('California', $geo->region);
    }

    #[Test]
    public function constructWithDefaultRegion(): void
    {
        $geo = new GeoInfo(countryCode: 'FR');

        self::assertSame('FR', $geo->countryCode);
        self::assertSame('', $geo->region);
    }

    #[Test]
    public function unknownFactoryReturnsXxCountryCode(): void
    {
        $geo = GeoInfo::unknown();

        self::assertSame('XX', $geo->countryCode);
        self::assertSame('', $geo->region);
    }
}
