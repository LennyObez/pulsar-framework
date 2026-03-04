<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\I18n\Region\Country;
use Pulsar\I18n\Region\CountryRegistry;
use Pulsar\I18n\Region\CurrencyResolver;
use Pulsar\I18n\Region\RegionMiddleware;
use Pulsar\I18n\Region\RegionResolver;

#[CoversClass(RegionMiddleware::class)]
final class RegionMiddlewareTest extends TestCase
{
    #[Test]
    public function setsAllFourRegionAttributesOnRequest(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        // Build a chainable request stub
        $attributes = [];
        $request = $this->buildChainableRequest(['pulsar_region' => 'BE'], [], $attributes);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        // Verify all four attributes were set
        self::assertArrayHasKey('_region_country', $attributes);
        self::assertInstanceOf(Country::class, $attributes['_region_country']);
        self::assertSame('BE', $attributes['_region_country']->code);

        self::assertArrayHasKey('_region_country_code', $attributes);
        self::assertSame('BE', $attributes['_region_country_code']);

        self::assertArrayHasKey('_region_currency', $attributes);
        self::assertSame('EUR', $attributes['_region_currency']);

        self::assertArrayHasKey('_region_payment_methods', $attributes);
        self::assertIsArray($attributes['_region_payment_methods']);
        self::assertContains('bancontact', $attributes['_region_payment_methods']);
    }

    #[Test]
    public function fallsBackToDefaultCountryWhenNoCookie(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        $attributes = [];
        $request = $this->buildChainableRequest([], [], $attributes);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertSame('US', $attributes['_region_country_code']);
        self::assertSame('USD', $attributes['_region_currency']);
    }

    #[Test]
    public function netherlandsRegionSetsIdealPayment(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        $attributes = [];
        $request = $this->buildChainableRequest(['pulsar_region' => 'NL'], [], $attributes);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        /** @var list<string> $paymentMethods */
        $paymentMethods = $attributes['_region_payment_methods'];
        self::assertContains('ideal', $paymentMethods);
        self::assertContains('sepa', $paymentMethods);
    }

    #[Test]
    public function ukRegionSetsGbpCurrency(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        $attributes = [];
        $request = $this->buildChainableRequest(['pulsar_region' => 'GB'], [], $attributes);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertSame('GBP', $attributes['_region_currency']);
    }

    #[Test]
    public function returnsResponseFromHandler(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        $attributes = [];
        $request = $this->buildChainableRequest([], [], $attributes);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $actualResponse = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $actualResponse);
    }

    #[Test]
    public function geoIpHeaderResolvesCountryWhenNoCookie(): void
    {
        $registry = new CountryRegistry();
        $regionResolver = new RegionResolver($registry, 'US');
        $currencyResolver = new CurrencyResolver($registry);
        $middleware = new RegionMiddleware($regionResolver, $currencyResolver);

        $attributes = [];
        $request = $this->buildChainableRequest([], ['CF-IPCountry' => 'DE'], $attributes);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertSame('DE', $attributes['_region_country_code']);
        self::assertSame('EUR', $attributes['_region_currency']);
    }

    /**
     * Build a ServerRequestInterface stub that properly chains withAttribute calls.
     *
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, mixed>  $attributes Passed by reference to capture set attributes
     */
    private function buildChainableRequest(
        array $cookies,
        array $headers,
        array &$attributes,
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => $headers[$name] ?? '',
        );

        // Chain withAttribute: capture value and return same stub
        $request->method('withAttribute')->willReturnCallback(
            static function (string $name, mixed $value) use ($request, &$attributes): ServerRequestInterface {
                $attributes[$name] = $value;

                return $request;
            },
        );

        // getAttribute reads back captured attributes
        $request->method('getAttribute')->willReturnCallback(
            static function (string $name, mixed $default = null) use (&$attributes): mixed {
                return $attributes[$name] ?? $default;
            },
        );

        return $request;
    }
}
