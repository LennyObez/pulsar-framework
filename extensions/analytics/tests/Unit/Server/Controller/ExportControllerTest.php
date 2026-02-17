<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Server\Controller\ExportController;
use Pulsar\Http\Message\ServerRequest;

use function str_contains;

final class ExportControllerTest extends TestCase
{
    private ExportController $controller;
    private PageViewRepositoryInterface&Stub $pageViewRepository;

    protected function setUp(): void
    {
        $this->pageViewRepository = $this->createStub(PageViewRepositoryInterface::class);
        $this->controller = new ExportController($this->pageViewRepository);
    }

    #[Test]
    public function exportReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/export');

        $response = $this->controller->export($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function exportReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->export($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function exportReturnsCsvWithHeaders(): void
    {
        $this->pageViewRepository->method('findBySite')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('.csv', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function exportCsvContainsHeaderRow(): void
    {
        $this->pageViewRepository->method('findBySite')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();

        self::assertStringStartsWith('id,pathname,visitor_id,session_id,referrer_source,country_code,device_type,browser,os,screen_width,is_bounce,created_at', $body);
    }

    #[Test]
    public function exportCsvContainsPageViewRows(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $pageViews = [
            new PageView(
                id: 'pv-1',
                siteId: 'site-001',
                visitorId: 'v-1',
                sessionId: 'sess-1',
                pathname: '/home',
                referrerSource: 'google',
                countryCode: 'US',
                deviceType: DeviceType::Desktop,
                browser: 'Chrome',
                os: 'Windows',
                screenWidth: 1920,
                isBounce: false,
                createdAt: $createdAt,
            ),
        ];
        $this->pageViewRepository->method('findBySite')->willReturn($pageViews);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();
        $lines = explode("\n", trim($body));

        self::assertCount(2, $lines);
        self::assertStringContainsString('pv-1', $lines[1]);
        self::assertStringContainsString('/home', $lines[1]);
        self::assertStringContainsString('desktop', $lines[1]);
        self::assertStringContainsString('Chrome', $lines[1]);
    }

    #[Test]
    public function exportFilenamContainsDateRange(): void
    {
        $this->pageViewRepository->method('findBySite')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: [
                'site_id' => 'site-001',
                'from' => '2025-01-01',
                'to' => '2025-01-31',
            ],
        );

        $response = $this->controller->export($request);
        $disposition = $response->getHeaderLine('Content-Disposition');

        self::assertStringContainsString('2025-01-01', $disposition);
        self::assertStringContainsString('2025-01-31', $disposition);
    }

    #[Test]
    #[DataProvider('csvFormulaInjectionProvider')]
    public function exportEscapesCsvFormulaInjection(string $maliciousValue): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $pageViews = [
            new PageView(
                id: 'pv-1',
                siteId: 'site-001',
                visitorId: 'v-1',
                sessionId: 'sess-1',
                pathname: $maliciousValue,
                referrerSource: '',
                countryCode: 'US',
                deviceType: DeviceType::Desktop,
                browser: 'Chrome',
                os: 'Windows',
                screenWidth: 1920,
                isBounce: true,
                createdAt: $createdAt,
            ),
        ];
        $this->pageViewRepository->method('findBySite')->willReturn($pageViews);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();
        $lines = explode("\n", trim($body));

        // The malicious value should be prefixed with a tab to prevent formula execution
        self::assertFalse(
            str_contains($lines[1], ',' . $maliciousValue . ','),
            'Raw formula-injectable value should be escaped in CSV',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function csvFormulaInjectionProvider(): iterable
    {
        yield 'equals sign' => ['=CMD()'];
        yield 'plus sign' => ['+CMD()'];
        yield 'minus sign' => ['-CMD()'];
        yield 'at sign' => ['@SUM(1+1)'];
    }

    #[Test]
    public function exportEscapesValuesContainingCommas(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $pageViews = [
            new PageView(
                id: 'pv-1',
                siteId: 'site-001',
                visitorId: 'v-1',
                sessionId: 'sess-1',
                pathname: '/page,with,commas',
                referrerSource: '',
                countryCode: 'US',
                deviceType: DeviceType::Desktop,
                browser: 'Chrome',
                os: 'Windows',
                screenWidth: 1920,
                isBounce: true,
                createdAt: $createdAt,
            ),
        ];
        $this->pageViewRepository->method('findBySite')->willReturn($pageViews);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();

        // Value with commas should be quoted
        self::assertStringContainsString('"/page,with,commas"', $body);
    }

    #[Test]
    public function exportEscapesValuesContainingDoubleQuotes(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $pageViews = [
            new PageView(
                id: 'pv-1',
                siteId: 'site-001',
                visitorId: 'v-1',
                sessionId: 'sess-1',
                pathname: '/page"with"quotes',
                referrerSource: '',
                countryCode: 'US',
                deviceType: DeviceType::Desktop,
                browser: 'Chrome',
                os: 'Windows',
                screenWidth: 1920,
                isBounce: true,
                createdAt: $createdAt,
            ),
        ];
        $this->pageViewRepository->method('findBySite')->willReturn($pageViews);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();

        // Double quotes should be escaped by doubling
        self::assertStringContainsString('""', $body);
    }

    #[Test]
    public function exportPassesDateRangeToRepository(): void
    {
        $repo = $this->createMock(PageViewRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findBySite')
            ->with(
                'site-001',
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-06-01'),
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-06-30'),
                10000,
            )
            ->willReturn([]);

        $controller = new ExportController($repo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: [
                'site_id' => 'site-001',
                'from' => '2025-06-01',
                'to' => '2025-06-30',
            ],
        );

        $controller->export($request);
    }

    #[Test]
    public function exportHandlesMultiplePageViews(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $pageViews = [];

        for ($i = 1; $i <= 5; $i++) {
            $pageViews[] = new PageView(
                id: "pv-{$i}",
                siteId: 'site-001',
                visitorId: "v-{$i}",
                sessionId: "sess-{$i}",
                pathname: "/page-{$i}",
                createdAt: $createdAt,
            );
        }

        $this->pageViewRepository->method('findBySite')->willReturn($pageViews);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/export',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->export($request);
        $body = (string) $response->getBody();
        $lines = explode("\n", trim($body));

        // 1 header + 5 data rows
        self::assertCount(6, $lines);
    }
}
