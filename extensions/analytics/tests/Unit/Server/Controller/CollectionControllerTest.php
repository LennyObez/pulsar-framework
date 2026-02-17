<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Server\Controller\CollectionController;
use Pulsar\Http\Message\ServerRequest;
use RuntimeException;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class CollectionControllerTest extends TestCase
{
    private CollectionController $controller;
    private TrackingServiceInterface&Stub $trackingService;
    private SiteRepositoryInterface&Stub $siteRepository;

    protected function setUp(): void
    {
        $this->trackingService = $this->createStub(TrackingServiceInterface::class);
        $this->siteRepository = $this->createStub(SiteRepositoryInterface::class);
        $this->controller = new CollectionController($this->trackingService, $this->siteRepository);
    }

    #[Test]
    public function collectReturns204ForValidPageview(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204ForValidEvent(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'event', 'site' => 'plsr_abc123'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204ForUnknownEventType(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'unknown_type', 'site' => 'plsr_abc123'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204ForInvalidJson(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            body: 'not valid json',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204ForNonArrayJson(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            body: '"just a string"',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenSiteTrackingIdMissing(): void
    {
        $request = $this->createCollectionRequest(
            ['type' => 'pageview'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenSiteTrackingIdEmpty(): void
    {
        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => ''],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenSiteTrackingIdIsNotString(): void
    {
        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 12345],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenSiteNotFound(): void
    {
        $this->siteRepository->method('findByTrackingId')->willReturn(null);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_unknown'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenNoOriginOrReferer(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            body: json_encode(['type' => 'pageview', 'site' => 'plsr_abc123'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenOriginIsNull(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            headers: ['Origin' => 'null'],
            body: json_encode(['type' => 'pageview', 'site' => 'plsr_abc123'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenOriginDomainDoesNotMatch(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            'https://malicious.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectAcceptsSubdomainOfRegisteredSite(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            'https://blog.example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectUsesRefererWhenOriginEmpty(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            headers: ['Referer' => 'https://example.com/some-page'],
            body: json_encode(['type' => 'pageview', 'site' => 'plsr_abc123'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenOriginUrlIsInvalid(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            headers: ['Origin' => '://invalid-url'],
            body: json_encode(['type' => 'pageview', 'site' => 'plsr_abc123'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectReturns204WhenTrackingServiceThrows(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);
        $this->trackingService->method('trackPageView')->willThrowException(new RuntimeException('DB error'));

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            'https://example.com/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function collectIsCaseInsensitiveOnDomainMatch(): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            'https://EXAMPLE.COM/page',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('rejectsPartialDomainMatchProvider')]
    public function collectRejectsPartialDomainMatch(string $origin): void
    {
        $site = $this->createSite();
        $this->siteRepository->method('findByTrackingId')->willReturn($site);

        $request = $this->createCollectionRequest(
            ['type' => 'pageview', 'site' => 'plsr_abc123'],
            $origin,
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectsPartialDomainMatchProvider(): iterable
    {
        yield 'suffix attack' => ['https://notexample.com/page'];
        yield 'prefix attack' => ['https://example.com.evil.com/page'];
    }

    #[Test]
    public function collectReturns204ForEmptyBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            body: '',
        );

        $response = $this->controller->collect($request);

        self::assertSame(204, $response->getStatusCode());
    }

    private function createSite(): Site
    {
        return new Site(
            id: 'site-001',
            domain: 'example.com',
            name: 'Example Site',
            trackingId: 'plsr_abc123',
            timezone: 'UTC',
            settings: [],
            createdAt: new DateTimeImmutable('2025-01-01'),
            updatedAt: new DateTimeImmutable('2025-01-01'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createCollectionRequest(array $payload, string $origin): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/analytics/collect',
            headers: ['Origin' => $origin],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
