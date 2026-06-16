<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
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
 * Tests that TrackingService correctly routes to queue vs direct insert
 * based on collectionDriver config.
 */
final class TrackingServiceQueueTest extends TestCase
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

    private function buildService(
        PageViewRepositoryInterface $pageViewRepo,
        string $collectionDriver = 'direct',
    ): TrackingService {
        return new TrackingService(
            keyManager: $this->keyManager,
            botDetector: $this->botDetector,
            referrerParser: $this->referrerParser,
            userAgentParser: $this->userAgentParser,
            sessionResolver: $this->sessionResolver,
            geoResolver: $this->geoResolver,
            pageViewRepository: $pageViewRepo,
            eventRepository: $this->createStub(EventRepositoryInterface::class),
            siteRepository: $this->siteRepo,
            config: new AnalyticsConfig(
                collectionDriver: $collectionDriver,
                privacy: new PrivacyConfig(respectDnt: false, requireConsent: false),
            ),
        );
    }

    #[Test]
    public function trackPageViewInsertsDirectlyWhenDirectDriver(): void
    {
        /** @var PageViewRepositoryInterface&MockObject $pageViewRepo */
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $pageViewRepo->expects(self::once())->method('insert');

        $service = $this->buildService($pageViewRepo, 'direct');
        $service->trackPageView($this->createRequest(), ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function trackPageViewFallsBackToDirectInsertWhenQueueDriverButNoQueueManager(): void
    {
        /** @var PageViewRepositoryInterface&MockObject $pageViewRepo */
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        // Queue driver configured but no QueueManager injected; falls back to direct insert
        $pageViewRepo->expects(self::once())->method('insert');

        $service = $this->buildService($pageViewRepo, 'queue');
        $service->trackPageView($this->createRequest(), ['url' => 'https://example.com/page']);
    }

    #[Test]
    public function collectionDriverDefaultsToDirect(): void
    {
        $config = AnalyticsConfig::fromArray([]);
        self::assertSame('direct', $config->collectionDriver);
    }

    #[Test]
    public function collectionDriverReadsQueueFromConfig(): void
    {
        $config = AnalyticsConfig::fromArray([
            'collection' => ['driver' => 'queue'],
        ]);
        self::assertSame('queue', $config->collectionDriver);
    }
}
