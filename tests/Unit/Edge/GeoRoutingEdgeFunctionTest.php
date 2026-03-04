<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\EdgeRequest;
use Pulsar\Edge\GeoRoutingEdgeFunction;

#[CoversClass(GeoRoutingEdgeFunction::class)]
final class GeoRoutingEdgeFunctionTest extends TestCase
{
    #[Test]
    public function redirects_matching_country(): void
    {
        $fn = new GeoRoutingEdgeFunction([
            'US' => '/en-us',
            'DE' => '/de',
            'FR' => '/fr',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'DE'],
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertSame(302, $response->statusCode);
        self::assertSame('/de', $response->headers['Location']);
    }

    #[Test]
    public function normalizes_country_code_to_uppercase(): void
    {
        $fn = new GeoRoutingEdgeFunction(['US' => '/en-us']);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'us'],
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertSame('/en-us', $response->headers['Location']);
    }

    #[Test]
    public function returns_null_for_unmatched_country_without_default(): void
    {
        $fn = new GeoRoutingEdgeFunction(['US' => '/en-us']);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'JP'],
        );

        self::assertNull($fn->handle($request));
    }

    #[Test]
    public function uses_default_redirect_for_unmatched_country(): void
    {
        $fn = new GeoRoutingEdgeFunction(
            countryRedirects: ['US' => '/en-us'],
            defaultRedirect: '/en',
        );

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'JP'],
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertSame('/en', $response->headers['Location']);
    }

    #[Test]
    public function returns_null_when_no_country_and_no_default(): void
    {
        $fn = new GeoRoutingEdgeFunction(['US' => '/en-us']);

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($fn->handle($request));
    }

    #[Test]
    public function uses_default_when_no_country_data(): void
    {
        $fn = new GeoRoutingEdgeFunction(
            countryRedirects: ['US' => '/en-us'],
            defaultRedirect: '/global',
        );

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertSame('/global', $response->headers['Location']);
    }

    #[Test]
    public function name_returns_geo_routing(): void
    {
        $fn = new GeoRoutingEdgeFunction([]);

        self::assertSame('geo-routing', $fn->name());
    }
}
