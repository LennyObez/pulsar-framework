<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Extension\Analytics\Internal\Service\ReferrerParser;
use Pulsar\Extension\Analytics\Internal\Service\SessionResolver;
use Pulsar\Extension\Analytics\Internal\Service\TrackingService;
use Pulsar\Extension\Analytics\Internal\Service\UserAgentParser;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use RuntimeException;

#[CoversClass(TrackingService::class)]
final class TrackingServiceTest extends TestCase
{
    private AnalyticsKeyManager $keyManager;
    private BotDetector $botDetector;
    private ReferrerParser $referrerParser;
    private UserAgentParser $userAgentParser;
    private SessionResolver $sessionResolver;
    private GeoLocationResolverInterface&Stub $geoResolver;
    private PageViewRepositoryInterface&Stub $pageViewRepo;
    private EventRepositoryInterface&Stub $eventRepo;
    private SiteRepositoryInterface&Stub $siteRepo;

    protected function setUp(): void
    {
        // Construct real instances for final classes
        $masterKey = \Pulsar\Security\Crypto\MasterKey::fromHex(bin2hex(random_bytes(32)));
        $this->keyManager = new AnalyticsKeyManager($masterKey);

        $this->botDetector = new BotDetector();
        $this->referrerParser = new ReferrerParser();
        $this->userAgentParser = new UserAgentParser();

        $sessionRepo = $this->createStub(\Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface::class);
        $sessionRepo->method('findActiveByVisitor')->willReturn(null);
        $this->sessionResolver = new SessionResolver($sessionRepo);

        $this->geoResolver = $this->createStub(GeoLocationResolverInterface::class);
        $this->pageViewRepo = $this->createStub(PageViewRepositoryInterface::class);
        $this->eventRepo = $this->createStub(EventRepositoryInterface::class);
        $this->siteRepo = $this->createStub(SiteRepositoryInterface::class);

        $this->geoResolver->method('resolve')->willReturn(new GeoLocation(0.0, 0.0, 'US'));
    }

    private function createService(
        ?AnalyticsConfig $config = null,
        ?ConsentManagerInterface $consentManager = null,
    ): TrackingService {
        return new TrackingService(
            keyManager: $this->keyManager,
            botDetector: $this->botDetector,
            referrerParser: $this->referrerParser,
            userAgentParser: $this->userAgentParser,
            sessionResolver: $this->sessionResolver,
            geoResolver: $this->geoResolver,
            pageViewRepository: $this->pageViewRepo,
            eventRepository: $this->eventRepo,
            siteRepository: $this->siteRepo,
            config: $config ?? new AnalyticsConfig(),
            consentManager: $consentManager,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, string> $headers
     * @param array<string, mixed> $serverParams
     */
    private function createRequest(
        array $attributes = [],
        array $headers = [],
        array $serverParams = [],
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);

        $request->method('getAttribute')->willReturnCallback(
            fn(string $name) => $attributes[$name] ?? null,
        );

        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $headers[$name] ?? '',
        );

        $request->method('getHeaders')->willReturn(
            array_map(fn($v) => [$v], $headers),
        );

        $request->method('getServerParams')->willReturn(
            array_merge(['REMOTE_ADDR' => '203.0.113.1'], $serverParams),
        );

        return $request;
    }

    private function createSite(string $id = 'site-1', string $domain = 'example.com'): Site
    {
        return new Site(
            id: $id,
            domain: $domain,
            name: 'Test Site',
            trackingId: 'plsr_test',
        );
    }

    // --- trackPageView tests ---

    #[Test]
    public function trackPageViewSkipsEmptyUrl(): void
    {
        $service = $this->createService();
        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
        );

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $svc->trackPageView($request, ['url' => '']);
    }

    #[Test]
    public function trackPageViewSkipsWhenSiteNotFound(): void
    {
        $this->siteRepo->method('findByTrackingId')->willReturn(null);

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest();
        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewSkipsBotTraffic(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        // Use a real bot user agent that the BotDetector recognizes
        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewRespectsDntHeader(): void
    {
        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(respectDnt: true),
        );

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            $config,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['DNT' => '1', 'User-Agent' => 'Mozilla/5.0'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewIgnoresDntWhenDisabled(): void
    {
        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(respectDnt: false),
        );

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            $config,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['DNT' => '1', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewRequiresConsentWhenEnabled(): void
    {
        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(requireConsent: true),
        );

        $consentManager = $this->createStub(ConsentManagerInterface::class);
        $consentManager->method('hasConsent')->willReturn(false);

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            $config,
            null,
            $consentManager,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewDeniesWithoutConsentManagerWhenRequired(): void
    {
        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(requireConsent: true),
        );

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            $config,
            null,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewInsertsPageViewOnSuccess(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (PageView $pv): bool {
                self::assertSame('site-1', $pv->siteId);
                self::assertSame('/page', $pv->pathname);
                self::assertSame('Direct / None', $pv->referrerSource);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewResolvesSiteFromAttribute(): void
    {
        $site = $this->createSite('attr-site');

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (PageView $pv): bool {
                self::assertSame('attr-site', $pv->siteId);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $site],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/']);
    }

    #[Test]
    public function trackPageViewFallsBackToTrackingIdLookup(): void
    {
        $site = $this->createSite('db-site');
        $this->siteRepo->method('findByTrackingId')->willReturn($site);

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (PageView $pv): bool {
                self::assertSame('db-site', $pv->siteId);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, [
            'url' => 'https://example.com/',
            'site' => 'plsr_test',
        ]);
    }

    #[Test]
    public function trackPageViewUsesClientIpFromRemoteAddr(): void
    {
        $config = new AnalyticsConfig(trustedProxies: []);

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            $config,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'accept-language' => 'en-US',
                'X-Forwarded-For' => '10.0.0.1',
            ],
            serverParams: ['REMOTE_ADDR' => '203.0.113.50'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    // --- trackEvent tests ---

    #[Test]
    public function trackEventSkipsEmptyEventName(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
        );

        $svc->trackEvent($request, ['event_name' => '']);
    }

    #[Test]
    public function trackEventInsertsEventOnSuccess(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (CustomEvent $event): bool {
                self::assertSame('signup', $event->eventName);
                self::assertSame('site-1', $event->siteId);
                self::assertSame('/register', $event->pathname);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackEvent($request, [
            'event_name' => 'signup',
            'url' => 'https://example.com/register',
        ]);
    }

    #[Test]
    public function trackEventIncludesRevenueValue(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (CustomEvent $event): bool {
                self::assertSame(49.99, $event->revenueValue);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackEvent($request, [
            'event_name' => 'purchase',
            'url' => 'https://example.com/checkout',
            'revenue_value' => 49.99,
        ]);
    }

    #[Test]
    public function trackEventIgnoresNonNumericRevenue(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (CustomEvent $event): bool {
                self::assertNull($event->revenueValue);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackEvent($request, [
            'event_name' => 'click',
            'url' => 'https://example.com/',
            'revenue_value' => 'invalid',
        ]);
    }

    #[Test]
    public function trackEventSkipsBotTraffic(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        // Use a real bot user agent that the BotDetector recognizes
        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'SemrushBot/7.0'],
        );

        $svc->trackEvent($request, ['event_name' => 'click', 'url' => 'https://example.com/']);
    }

    #[Test]
    public function trackEventRespectsDntHeader(): void
    {
        $config = new AnalyticsConfig(
            privacy: new PrivacyConfig(respectDnt: true),
        );

        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::never())->method('insert');

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            $config,
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['DNT' => '1', 'User-Agent' => 'Mozilla/5.0'],
        );

        $svc->trackEvent($request, ['event_name' => 'click', 'url' => 'https://example.com/']);
    }

    #[Test]
    public function trackPageViewHandlesGeoResolutionFailure(): void
    {
        $geoResolver = $this->createStub(GeoLocationResolverInterface::class);
        $geoResolver->method('resolve')->willThrowException(new RuntimeException('DNS failed'));

        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (PageView $pv): bool {
                self::assertSame('XX', $pv->countryCode);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewExtractsPathFromUrl(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (PageView $pv): bool {
                self::assertSame('/blog/post-1', $pv->pathname);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $pageViewRepo,
            $this->eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackPageView($request, ['url' => 'https://example.com/blog/post-1?utm_source=twitter']);
    }

    #[Test]
    public function trackEventIncludesCustomProperties(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $eventRepo->expects(self::once())
            ->method('insert')
            ->with(self::callback(function (CustomEvent $event): bool {
                self::assertSame(['plan' => 'pro', 'source' => 'homepage'], $event->eventProps);

                return true;
            }));

        $svc = new TrackingService(
            $this->keyManager,
            $this->botDetector,
            $this->referrerParser,
            $this->userAgentParser,
            $this->sessionResolver,
            $this->geoResolver,
            $this->pageViewRepo,
            $eventRepo,
            $this->siteRepo,
            new AnalyticsConfig(),
        );

        $request = $this->createRequest(
            attributes: ['analytics.site' => $this->createSite()],
            headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'accept-language' => 'en-US'],
        );

        $svc->trackEvent($request, [
            'event_name' => 'signup',
            'url' => 'https://example.com/',
            'event_props' => ['plan' => 'pro', 'source' => 'homepage'],
        ]);
    }
}
