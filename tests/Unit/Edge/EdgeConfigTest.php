<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\EdgeConfig;

#[CoversClass(EdgeConfig::class)]
final class EdgeConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledWithNoFunctions(): void
    {
        $config = new EdgeConfig();

        self::assertFalse($config->enabled);
        self::assertSame('CF-IPCountry', $config->geoCountryHeader);
        self::assertSame([], $config->functions);
        self::assertFalse($config->isUsable());
    }

    #[Test]
    public function fromArrayBuildsAbTestAndGeoFunctions(): void
    {
        $config = EdgeConfig::fromArray([
            'enabled' => true,
            'geo_country_header' => 'X-Geo-Country',
            'ab_tests' => [
                ['experiment_name' => 'home', 'variants' => ['a' => '/a', 'b' => '/b']],
            ],
            'geo_redirects' => [
                'country_redirects' => ['DE' => '/de'],
                'default_redirect' => '/intl',
            ],
        ]);

        self::assertTrue($config->isUsable());
        self::assertSame('X-Geo-Country', $config->geoCountryHeader);
        self::assertCount(2, $config->functions, 'one A/B + one geo function');
        self::assertSame('ab-test-home', $config->functions[0]->name());
        self::assertSame('geo-routing', $config->functions[1]->name());
    }

    #[Test]
    public function skipsAbTestsMissingExperimentOrVariants(): void
    {
        $config = EdgeConfig::fromArray([
            'enabled' => true,
            'ab_tests' => [
                ['variants' => ['a' => '/a']],            // no experiment_name
                ['experiment_name' => 'x', 'variants' => []], // no variants
                ['experiment_name' => 'ok', 'variants' => ['a' => '/a']],
            ],
        ]);

        self::assertCount(1, $config->functions);
    }

    #[Test]
    public function skipsGeoRedirectsWithNothingConfigured(): void
    {
        $config = EdgeConfig::fromArray([
            'enabled' => true,
            'geo_redirects' => ['country_redirects' => [], 'default_redirect' => null],
        ]);

        self::assertSame([], $config->functions);
        self::assertFalse($config->isUsable());
    }
}
