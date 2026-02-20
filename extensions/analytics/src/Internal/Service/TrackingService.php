<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\GeoInfo;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Domain\VisitorId;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use Throwable;

use function in_array;
use function is_array;
use function is_string;

/**
 * Core tracking pipeline: validate → enrich → persist page views and events.
 */
#[Internal(reason: 'Tracking pipeline — use TrackingServiceInterface')]
final readonly class TrackingService implements TrackingServiceInterface
{
    public function __construct(
        private AnalyticsKeyManager $keyManager,
        private BotDetector $botDetector,
        private ReferrerParser $referrerParser,
        private UserAgentParser $userAgentParser,
        private SessionResolver $sessionResolver,
        private GeoLocationResolverInterface $geoResolver,
        private PageViewRepositoryInterface $pageViewRepository,
        private EventRepositoryInterface $eventRepository,
        private SiteRepositoryInterface $siteRepository,
        private AnalyticsConfig $config,
    ) {}

    #[Override]
    public function trackPageView(ServerRequestInterface $request, array $payload): void
    {
        $url = (string) ($payload['url'] ?? '');

        if ($url === '') {
            return;
        }

        $site = $this->resolveSite($request, $payload);

        if ($site === null) {
            return;
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        $headers = $this->flattenHeaders($request);

        if ($this->botDetector->isBot($userAgent, $headers)) {
            return;
        }

        if ($this->config->privacy->respectDnt && $request->getHeaderLine('DNT') === '1') {
            return;
        }

        $ip = $this->getClientIp($request);
        $now = new DateTimeImmutable();

        $key = $this->keyManager->visitorKey();
        $todayDay = $this->keyManager->utcDayNumber();
        $visitorId = VisitorId::generate($ip, $userAgent, $key, $todayDay);

        $yesterdayDay = $this->keyManager->utcDayNumber(1);
        $yesterdayVisitorId = VisitorId::generate($ip, $userAgent, $key, $yesterdayDay);

        $session = $this->sessionResolver->resolve(
            $visitorId,
            $now,
            $this->extractPathname($url),
            $site->id,
            $key,
            $yesterdayVisitorId,
        );

        $referrerUrl = (string) ($payload['referrer'] ?? '');
        $referrer = $this->referrerParser->parse($referrerUrl, $site->domain);
        $device = $this->userAgentParser->parse($userAgent);
        $geo = $this->resolveGeo($ip);

        $pageView = new PageView(
            id: $this->generateId(),
            siteId: $site->id,
            visitorId: $visitorId->hash,
            sessionId: $session->sessionId,
            pathname: $this->extractPathname($url),
            referrerSource: $referrer->source,
            utmSource: (string) ($payload['utm_source'] ?? $referrer->source),
            utmMedium: (string) ($payload['utm_medium'] ?? $referrer->medium),
            utmCampaign: (string) ($payload['utm_campaign'] ?? $referrer->campaign),
            utmTerm: (string) ($payload['utm_term'] ?? ''),
            utmContent: (string) ($payload['utm_content'] ?? ''),
            countryCode: $geo->countryCode,
            deviceType: $device->deviceType,
            browser: $device->browser,
            os: $device->os,
            screenWidth: (int) ($payload['screen_width'] ?? 0),
            isBounce: $session->pageCount <= 1,
            createdAt: $now,
        );

        $this->pageViewRepository->insert($pageView);
    }

    #[Override]
    public function trackEvent(ServerRequestInterface $request, array $payload): void
    {
        $eventName = (string) ($payload['event_name'] ?? '');

        if ($eventName === '') {
            return;
        }

        $site = $this->resolveSite($request, $payload);

        if ($site === null) {
            return;
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        $headers = $this->flattenHeaders($request);

        if ($this->botDetector->isBot($userAgent, $headers)) {
            return;
        }

        if ($this->config->privacy->respectDnt && $request->getHeaderLine('DNT') === '1') {
            return;
        }

        $ip = $this->getClientIp($request);
        $now = new DateTimeImmutable();

        $key = $this->keyManager->visitorKey();
        $todayDay = $this->keyManager->utcDayNumber();
        $visitorId = VisitorId::generate($ip, $userAgent, $key, $todayDay);
        $url = (string) ($payload['url'] ?? '');

        $yesterdayDay = $this->keyManager->utcDayNumber(1);
        $yesterdayVisitorId = VisitorId::generate($ip, $userAgent, $key, $yesterdayDay);

        $session = $this->sessionResolver->resolve(
            $visitorId,
            $now,
            $this->extractPathname($url),
            $site->id,
            $key,
            $yesterdayVisitorId,
        );

        $eventProps = is_array($payload['event_props'] ?? null) ? $payload['event_props'] : [];
        $revenueValue = isset($payload['revenue_value']) ? (float) $payload['revenue_value'] : null;

        $event = new CustomEvent(
            id: $this->generateId(),
            siteId: $site->id,
            visitorId: $visitorId->hash,
            sessionId: $session->sessionId,
            eventName: $eventName,
            eventProps: $eventProps,
            revenueValue: $revenueValue,
            pathname: $this->extractPathname($url),
            createdAt: $now,
        );

        $this->eventRepository->insert($event);
    }

    /**
     * Resolve the site from the pre-validated request attribute or fall back to DB lookup.
     *
     * The CollectionController validates the origin and attaches the Site object
     * to the request attribute 'analytics.site' to avoid a duplicate DB lookup.
     */
    private function resolveSite(ServerRequestInterface $request, array $payload): ?Site
    {
        $site = $request->getAttribute('analytics.site');

        if ($site instanceof Site) {
            return $site;
        }

        // Fallback for direct TrackingServiceInterface usage outside CollectionController
        $trackingId = (string) ($payload['site'] ?? '');

        if ($trackingId === '') {
            return null;
        }

        return $this->siteRepository->findByTrackingId($trackingId);
    }

    /**
     * Extract the client IP, trusting X-Forwarded-For only from configured trusted proxies.
     *
     * Without trusted proxy validation, any client can spoof their IP via XFF,
     * bypassing rate limiting and corrupting visitor identification.
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

        if ($forwardedFor !== '' && $this->isTrustedProxy($remoteAddr)) {
            $ips = explode(',', $forwardedFor);

            return trim($ips[0]);
        }

        return $remoteAddr;
    }

    private function isTrustedProxy(string $remoteAddr): bool
    {
        if ($this->config->trustedProxies === []) {
            return false;
        }

        return in_array($remoteAddr, $this->config->trustedProxies, true);
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(ServerRequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return $headers;
    }

    private function extractPathname(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function resolveGeo(string $ip): GeoInfo
    {
        try {
            $geo = $this->geoResolver->resolve($ip);

            if ($geo !== null) {
                return new GeoInfo(
                    countryCode: $geo->country !== '' ? $geo->country : 'XX',
                );
            }
        } catch (Throwable) {
            // Geo resolution is best-effort
        }

        return GeoInfo::unknown();
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(18));
    }
}
