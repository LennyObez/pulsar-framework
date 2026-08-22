<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;
use Pulsar\Extension\Analytics\Server\Controller\BreakdownController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class BreakdownControllerTest extends TestCase
{
    private BreakdownController $controller;
    private StatsServiceInterface&Stub $statsService;

    protected function setUp(): void
    {
        $this->statsService = $this->createStub(StatsServiceInterface::class);
        $this->controller = new BreakdownController($this->statsService);
    }

    #[Test]
    public function breakdownReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/breakdown');

        $response = $this->controller->breakdown($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function breakdownReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->breakdown($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function breakdownReturnsBadRequestForInvalidDimension(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001', 'dimension' => 'nonexistent'],
        );

        $response = $this->controller->breakdown($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid dimension', $body['error']);
    }

    #[Test]
    #[DataProvider('validDimensionProvider')]
    public function breakdownAcceptsAllValidDimensions(string $dimensionStr, BreakdownDimension $expected): void
    {
        $breakdownData = [
            ['name' => '/about', 'visitors' => 50, 'pageviews' => 80],
        ];
        $this->statsService->method('getBreakdown')->willReturn($breakdownData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001', 'dimension' => $dimensionStr],
        );

        $response = $this->controller->breakdown($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['data']);
    }

    /**
     * @return iterable<string, array{string, BreakdownDimension}>
     */
    public static function validDimensionProvider(): iterable
    {
        foreach (BreakdownDimension::cases() as $case) {
            yield $case->value => [$case->value, $case];
        }
    }

    #[Test]
    public function breakdownDefaultsToPagDimension(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getBreakdown')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                BreakdownDimension::Page,
                10,
            )
            ->willReturn([]);

        $controller = new BreakdownController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001'],
        );

        $controller->breakdown($request);
    }

    #[Test]
    public function breakdownClampsLimitToMinimumOf1(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getBreakdown')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                BreakdownDimension::Page,
                1,
            )
            ->willReturn([]);

        $controller = new BreakdownController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001', 'limit' => '0'],
        );

        $controller->breakdown($request);
    }

    #[Test]
    public function breakdownClampsLimitToMaximumOf100(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getBreakdown')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                BreakdownDimension::Page,
                100,
            )
            ->willReturn([]);

        $controller = new BreakdownController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001', 'limit' => '999'],
        );

        $controller->breakdown($request);
    }

    #[Test]
    public function breakdownUsesDefaultLimitForNonNumeric(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getBreakdown')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                BreakdownDimension::Page,
                10,
            )
            ->willReturn([]);

        $controller = new BreakdownController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: ['site_id' => 'site-001', 'limit' => 'abc'],
        );

        $controller->breakdown($request);
    }

    #[Test]
    public function breakdownPassesCustomDateRange(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getBreakdown')
            ->with(
                'site-001',
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-06-01'),
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-06-30'),
                BreakdownDimension::Page,
                10,
            )
            ->willReturn([]);

        $controller = new BreakdownController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/breakdown',
            queryParams: [
                'site_id' => 'site-001',
                'from' => '2025-06-01',
                'to' => '2025-06-30',
            ],
        );

        $controller->breakdown($request);
    }
}
