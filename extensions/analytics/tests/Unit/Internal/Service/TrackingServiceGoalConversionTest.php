<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
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
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

/**
 * Tests that TrackingService triggers goal conversion checks after
 * page view and event inserts when a GoalServiceInterface is injected.
 *
 * @covers \Pulsar\Extension\Analytics\Internal\Service\TrackingService
 */
final class TrackingServiceGoalConversionTest extends TestCase
{
    private AnalyticsKeyManager $keyManager;
    private BotDetector $botDetector;
    private ReferrerParser $referrerParser;
    private UserAgentParser $userAgentParser;
    private SessionResolver $sessionResolver;
    private GeoLocationResolverInterface&Stub $geoResolver;
    private SiteRepositoryInterface&Stub $siteRepo;

    protected function setUp(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $masterKey->method('deriveSubKey')->willReturn(str_repeat('k', 32));

        $this->keyManager = new AnalyticsKeyManager($masterKey);
        $this->botDetector = new BotDetector();
        $this->referrerParser = new ReferrerParser();
        $this->userAgentParser = new UserAgentParser();

        $sessionRepo = $this->createStub(SessionRepositoryInterface::class);
        $sessionRepo->method('findActiveByVisitor')->willReturn(null);
        $this->sessionResolver = new SessionResolver($sessionRepo);

        $this->geoResolver = $this->createStub(GeoLocationResolverInterface::class);
        $this->geoResolver->method('resolve')->willReturn(null);

        $this->siteRepo = $this->createStub(SiteRepositoryInterface::class);
    }

    private function buildService(
        PageViewRepositoryInterface $pageViewRepo,
        EventRepositoryInterface $eventRepo,
        ?GoalServiceInterface $goalService,
    ): TrackingService {
        return new TrackingService(
            keyManager: $this->keyManager,
            botDetector: $this->botDetector,
            referrerParser: $this->referrerParser,
            userAgentParser: $this->userAgentParser,
            sessionResolver: $this->sessionResolver,
            geoResolver: $this->geoResolver,
            pageViewRepository: $pageViewRepo,
            eventRepository: $eventRepo,
            siteRepository: $this->siteRepo,
            config: new AnalyticsConfig(privacy: new PrivacyConfig(respectDnt: false, requireConsent: false)),
            goalService: $goalService,
        );
    }

    private function createRequest(): ServerRequestInterface&Stub
    {
        $site = new Site(
            id: 'site-1',
            domain: 'example.com',
            name: 'Test Site',
            trackingId: 'plsr_test',
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['analytics.site', null, $site],
        ]);
        $request->method('getHeaderLine')->willReturnMap([
            ['User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) TestBrowser/1.0'],
            ['DNT', ''],
            ['X-Forwarded-For', ''],
        ]);
        $request->method('getHeaders')->willReturn([
            'accept-language' => ['en-US'],
        ]);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.168.1.1']);

        return $request;
    }

    #[Test]
    public function trackPageViewCallsGoalServiceCheckPageViewConversions(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $pageViewRepo->expects(self::once())->method('insert');
        $goalService->expects(self::once())
            ->method('checkPageViewConversions')
            ->with(self::isInstanceOf(PageView::class));

        $service = $this->buildService($pageViewRepo, $this->createStub(EventRepositoryInterface::class), $goalService);
        $service->trackPageView($this->createRequest(), ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackEventCallsGoalServiceCheckEventConversions(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $eventRepo->expects(self::once())->method('insert');
        $goalService->expects(self::once())
            ->method('checkEventConversions')
            ->with(self::isInstanceOf(CustomEvent::class));

        $service = $this->buildService($this->createStub(PageViewRepositoryInterface::class), $eventRepo, $goalService);
        $service->trackEvent($this->createRequest(), [
            'event_name' => 'signup',
            'url' => 'https://example.com/signup',
        ]);
    }

    #[Test]
    public function trackPageViewWorksWithoutGoalService(): void
    {
        $service = $this->buildService(
            $this->createStub(PageViewRepositoryInterface::class),
            $this->createStub(EventRepositoryInterface::class),
            null,
        );

        $service->trackPageView($this->createRequest(), ['url' => 'https://example.com/page']);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function trackEventWorksWithoutGoalService(): void
    {
        $service = $this->buildService(
            $this->createStub(PageViewRepositoryInterface::class),
            $this->createStub(EventRepositoryInterface::class),
            null,
        );

        $service->trackEvent($this->createRequest(), [
            'event_name' => 'purchase',
            'url' => 'https://example.com/checkout',
        ]);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function goalConversionNotCalledWhenUrlIsEmpty(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $pageViewRepo->expects(self::never())->method('insert');
        $goalService->expects(self::never())->method('checkPageViewConversions');

        $service = $this->buildService($pageViewRepo, $this->createStub(EventRepositoryInterface::class), $goalService);
        $service->trackPageView($this->createRequest(), ['url' => '']);
    }

    #[Test]
    public function goalConversionNotCalledWhenEventNameIsEmpty(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $eventRepo->expects(self::never())->method('insert');
        $goalService->expects(self::never())->method('checkEventConversions');

        $service = $this->buildService($this->createStub(PageViewRepositoryInterface::class), $eventRepo, $goalService);
        $service->trackEvent($this->createRequest(), [
            'event_name' => '',
            'url' => 'https://example.com/page',
        ]);
    }

    #[Test]
    public function pageViewConversionReceivesCorrectPageViewData(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $pageViewRepo->expects(self::once())->method('insert');

        $capturedPageView = null;
        $goalService->expects(self::once())
            ->method('checkPageViewConversions')
            ->with(self::callback(static function (PageView $pv) use (&$capturedPageView): bool {
                $capturedPageView = $pv;
                return true;
            }));

        $service = $this->buildService($pageViewRepo, $this->createStub(EventRepositoryInterface::class), $goalService);
        $service->trackPageView($this->createRequest(), ['url' => 'https://example.com/pricing']);

        self::assertNotNull($capturedPageView);
        self::assertSame('/pricing', $capturedPageView->pathname);
        self::assertSame('site-1', $capturedPageView->siteId);
    }

    #[Test]
    public function eventConversionReceivesCorrectEventData(): void
    {
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $eventRepo->expects(self::once())->method('insert');

        $capturedEvent = null;
        $goalService->expects(self::once())
            ->method('checkEventConversions')
            ->with(self::callback(static function (CustomEvent $ev) use (&$capturedEvent): bool {
                $capturedEvent = $ev;
                return true;
            }));

        $service = $this->buildService($this->createStub(PageViewRepositoryInterface::class), $eventRepo, $goalService);
        $service->trackEvent($this->createRequest(), [
            'event_name' => 'purchase',
            'url' => 'https://example.com/thank-you',
            'revenue_value' => 99.99,
        ]);

        self::assertNotNull($capturedEvent);
        self::assertSame('purchase', $capturedEvent->eventName);
        self::assertSame('site-1', $capturedEvent->siteId);
        self::assertSame(99.99, $capturedEvent->revenueValue);
    }

    #[Test]
    public function goalConversionNotCalledWhenSiteNotResolved(): void
    {
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $goalService = $this->createMock(GoalServiceInterface::class);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturnMap([
            ['User-Agent', 'Mozilla/5.0'],
            ['DNT', ''],
        ]);
        $request->method('getHeaders')->willReturn([]);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        $pageViewRepo->expects(self::never())->method('insert');
        $goalService->expects(self::never())->method('checkPageViewConversions');

        $service = $this->buildService($pageViewRepo, $this->createStub(EventRepositoryInterface::class), $goalService);
        $service->trackPageView($request, ['url' => 'https://example.com/page', 'site' => '']);
    }
}
