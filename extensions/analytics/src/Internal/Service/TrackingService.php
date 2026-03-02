<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\GeoInfo;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Domain\VisitorId;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Extension\Analytics\Internal\Queue\ProcessPageViewJob;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Queue\QueueManager;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use Throwable;

use function array_map;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Core tracking pipeline: validate → enrich → persist page views and events.
 */
#[Internal(reason: 'Tracking pipeline; use TrackingServiceInterface')]
final readonly class TrackingService implements TrackingServiceInterface
{
    /**
     * The consent purpose identifier used when requireConsent is enabled.
     */
    private const string CONSENT_PURPOSE = 'analytics';

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
        private ?GoalServiceInterface $goalService = null,
        private ?ConsentManagerInterface $consentManager = null,
        private ?QueueManager $queueManager = null,
    ) {}

    #[Override]
    public function trackPageView(ServerRequestInterface $request, array $payload): void
    {
        $url = self::str($payload, 'url');

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

        if (!$this->hasConsentIfRequired($ip)) {
            return;
        }

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

        $referrerUrl = self::str($payload, 'referrer');
        $referrerUrl = $this->anonymizeReferrerUrl($referrerUrl);
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
            utmSource: self::str($payload, 'utm_source', $referrer->source),
            utmMedium: self::str($payload, 'utm_medium', $referrer->medium),
            utmCampaign: self::str($payload, 'utm_campaign', $referrer->campaign),
            utmTerm: self::str($payload, 'utm_term'),
            utmContent: self::str($payload, 'utm_content'),
            countryCode: $geo->countryCode,
            deviceType: $device->deviceType,
            browser: $device->browser,
            os: $device->os,
            screenWidth: self::intVal($payload, 'screen_width'),
            isBounce: $session->pageCount <= 1,
            createdAt: $now,
        );

        if ($this->config->collectionDriver === 'queue' && $this->queueManager !== null) {
            $this->queueManager->dispatch(
                ProcessPageViewJob::class,
                json_encode([
                    'id' => $pageView->id,
                    'site_id' => $pageView->siteId,
                    'visitor_id' => $pageView->visitorId,
                    'session_id' => $pageView->sessionId,
                    'pathname' => $pageView->pathname,
                    'referrer_source' => $pageView->referrerSource,
                    'utm_source' => $pageView->utmSource,
                    'utm_medium' => $pageView->utmMedium,
                    'utm_campaign' => $pageView->utmCampaign,
                    'utm_term' => $pageView->utmTerm,
                    'utm_content' => $pageView->utmContent,
                    'country_code' => $pageView->countryCode,
                    'device_type' => $pageView->deviceType->value,
                    'browser' => $pageView->browser,
                    'os' => $pageView->os,
                    'screen_width' => $pageView->screenWidth,
                    'is_bounce' => $pageView->isBounce,
                    'created_at' => $pageView->createdAt->format('Y-m-d H:i:s'),
                ], JSON_THROW_ON_ERROR),
                'analytics',
            );
        } else {
            $this->pageViewRepository->insert($pageView);
        }

        $this->goalService?->checkPageViewConversions($pageView);
    }

    #[Override]
    public function trackEvent(ServerRequestInterface $request, array $payload): void
    {
        $eventName = self::str($payload, 'event_name');

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

        if (!$this->hasConsentIfRequired($ip)) {
            return;
        }

        $now = new DateTimeImmutable();

        $key = $this->keyManager->visitorKey();
        $todayDay = $this->keyManager->utcDayNumber();
        $visitorId = VisitorId::generate($ip, $userAgent, $key, $todayDay);
        $url = self::str($payload, 'url');

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

        /** @var array<string, mixed> $eventProps */
        $eventProps = is_array($payload['event_props'] ?? null) ? $payload['event_props'] : [];
        $rawRevenue = $payload['revenue_value'] ?? null;
        $revenueValue = is_float($rawRevenue) || is_int($rawRevenue) ? (float) $rawRevenue : null;

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
        $this->goalService?->checkEventConversions($event);
    }

    /**
     * Resolve the site from the pre-validated request attribute or fall back to DB lookup.
     *
     * The CollectionController validates the origin and attaches the Site object
     * to the request attribute 'analytics.site' to avoid a duplicate DB lookup.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveSite(ServerRequestInterface $request, array $payload): ?Site
    {
        $site = $request->getAttribute('analytics.site');

        if ($site instanceof Site) {
            return $site;
        }

        // Fallback for direct TrackingServiceInterface usage outside CollectionController
        $trackingId = self::str($payload, 'site');

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
        $raw = $serverParams['REMOTE_ADDR'] ?? null;
        $remoteAddr = is_string($raw) ? $raw : '127.0.0.1';

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

        if ($forwardedFor !== '' && $this->isTrustedProxy($remoteAddr)) {
            $ips = array_map(trim(...), explode(',', $forwardedFor));

            // Walk right-to-left: rightmost IPs are closest to server (most trusted)
            // Find the first IP that is NOT a trusted proxy
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if (!$this->isTrustedProxy($ips[$i])) {
                    return $ips[$i];
                }
            }

            // All IPs are trusted proxies: use the leftmost (original client)
            if ($ips !== []) {
                return $ips[0];
            }
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
            $headers[strtolower((string) $name)] = $values[0] ?? '';
        }

        return $headers;
    }

    /**
     * Strip query strings and fragments from referrer URLs when anonymization is enabled.
     *
     * Preserves scheme, host, and path: removes query parameters and fragments
     * that could contain PII (e.g., search terms, email addresses, session tokens).
     */
    private function anonymizeReferrerUrl(string $referrerUrl): string
    {
        if (!$this->config->privacy->anonymizeReferrer || $referrerUrl === '') {
            return $referrerUrl;
        }

        $parsed = parse_url($referrerUrl);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return $referrerUrl;
        }

        $anonymized = $parsed['scheme'] . '://' . $parsed['host'];

        if (isset($parsed['port'])) {
            $anonymized .= ':' . $parsed['port'];
        }

        if (isset($parsed['path'])) {
            $anonymized .= $parsed['path'];
        }

        return $anonymized;
    }

    /**
     * Check whether consent is granted when requireConsent is enabled.
     *
     * Uses the visitor's IP as the subject identifier for consent lookup,
     * since analytics tracking is cookieless and IP is the only identifier
     * available before visitor ID generation.
     */
    private function hasConsentIfRequired(string $ip): bool
    {
        if (!$this->config->privacy->requireConsent) {
            return true;
        }

        if ($this->consentManager === null) {
            return false;
        }

        return $this->consentManager->hasConsent($ip, self::CONSENT_PURPOSE);
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

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intVal(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }
}
