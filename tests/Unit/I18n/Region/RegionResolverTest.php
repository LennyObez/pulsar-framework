<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\I18n\Region\CountryRegistry;
use Pulsar\I18n\Region\RegionResolver;

#[CoversClass(RegionResolver::class)]
final class RegionResolverTest extends TestCase
{
    private CountryRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CountryRegistry();
    }

    #[Test]
    public function cookieHasHighestPriority(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: ['pulsar_region' => 'BE'],
            headers: ['X-Country-Code' => 'FR', 'Accept-Language' => 'de-DE'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('BE', $country->code);
    }

    #[Test]
    public function geoIpFallsBackWhenNoCookie(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: [],
            headers: ['X-Country-Code' => 'NL', 'Accept-Language' => 'de-DE'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('NL', $country->code);
    }

    #[Test]
    #[DataProvider('geoIpHeaderProvider')]
    public function supportsMultipleGeoIpHeaders(string $headerName): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: [],
            headers: [$headerName => 'DE'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('DE', $country->code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function geoIpHeaderProvider(): iterable
    {
        yield 'X-Country-Code' => ['X-Country-Code'];
        yield 'CF-IPCountry' => ['CF-IPCountry'];
        yield 'X-Vercel-IP-Country' => ['X-Vercel-IP-Country'];
        yield 'X-GeoIP-Country' => ['X-GeoIP-Country'];
    }

    #[Test]
    public function acceptLanguageFallsBackWhenNoGeoIp(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: [],
            headers: ['Accept-Language' => 'fr-BE,fr;q=0.9,en;q=0.8'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('BE', $country->code);
    }

    #[Test]
    public function defaultCountryUsedWhenAllSourcesMissing(): void
    {
        $resolver = new RegionResolver($this->registry, 'GB');
        $request = $this->createRequest(cookies: [], headers: []);

        $country = $resolver->resolve($request);

        self::assertSame('GB', $country->code);
    }

    #[Test]
    public function defaultFallsToUsWhenConfiguredDefaultInvalid(): void
    {
        $resolver = new RegionResolver($this->registry, 'XX');
        $request = $this->createRequest(cookies: [], headers: []);

        $country = $resolver->resolve($request);

        self::assertSame('US', $country->code);
    }

    #[Test]
    public function invalidCookieCodeIsIgnored(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: ['pulsar_region' => 'INVALID'],
            headers: ['X-Country-Code' => 'FR'],
        );

        $country = $resolver->resolve($request);

        // Falls through to GeoIP
        self::assertSame('FR', $country->code);
    }

    #[Test]
    public function emptyCookieValueIsIgnored(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: ['pulsar_region' => ''],
            headers: ['X-Country-Code' => 'IT'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('IT', $country->code);
    }

    #[Test]
    public function invalidGeoIpCodeIsIgnored(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: [],
            headers: ['X-Country-Code' => 'NOPE', 'Accept-Language' => 'es-ES'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('ES', $country->code);
    }

    #[Test]
    public function acceptLanguageWithoutRegionFallsToDefault(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(
            cookies: [],
            headers: ['Accept-Language' => 'en,fr;q=0.9'],
        );

        $country = $resolver->resolve($request);

        self::assertSame('US', $country->code);
    }

    #[Test]
    public function cookieNameReturnsExpectedValue(): void
    {
        $resolver = new RegionResolver($this->registry);

        self::assertSame('pulsar_region', $resolver->cookieName());
    }

    #[Test]
    public function resolvedCountryHasValidProperties(): void
    {
        $resolver = new RegionResolver($this->registry, 'US');
        $request = $this->createRequest(cookies: ['pulsar_region' => 'CH'], headers: []);

        $country = $resolver->resolve($request);

        self::assertSame('CH', $country->code);
        self::assertSame('Switzerland', $country->name);
        self::assertSame('CHF', $country->currency);
        self::assertNotEmpty($country->languages);
        self::assertNotEmpty($country->flag);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function createRequest(array $cookies, array $headers): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn($cookies);

        $request->method('getHeaderLine')->willReturnCallback(
            static function (string $name) use ($headers): string {
                return $headers[$name] ?? '';
            },
        );

        return $request;
    }
}
