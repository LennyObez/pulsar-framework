<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Controller\Api\RegionApiController;
use Pulsar\I18n\Region\CountryRegistry;

#[CoversClass(RegionApiController::class)]
final class RegionApiControllerTest extends TestCase
{
    #[Test]
    public function returnsJsonResponse(): void
    {
        $registry = new CountryRegistry();
        $controller = new RegionApiController($registry);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller();

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function responseIsCacheable(): void
    {
        $registry = new CountryRegistry();
        $controller = new RegionApiController($registry);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller();
        $cacheControl = $response->getHeaderLine('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=', $cacheControl);
    }

    #[Test]
    public function responseBodyContainsCountryData(): void
    {
        $registry = new CountryRegistry();
        $controller = new RegionApiController($registry);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $decoded;
        self::assertArrayHasKey('europe', $data);
        self::assertNotEmpty($data['europe']);
    }

    #[Test]
    public function eachCountryInResponseHasRequiredFields(): void
    {
        $registry = new CountryRegistry();
        $controller = new RegionApiController($registry);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $decoded;

        /** @var array<string, mixed> $firstCountry */
        $firstCountry = $data['europe'][0];
        self::assertArrayHasKey('code', $firstCountry);
        self::assertArrayHasKey('name', $firstCountry);
        self::assertArrayHasKey('continent', $firstCountry);
        self::assertArrayHasKey('languages', $firstCountry);
        self::assertArrayHasKey('currency', $firstCountry);
        self::assertArrayHasKey('flag', $firstCountry);
    }
}
