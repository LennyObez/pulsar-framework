<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\DailyStats;
use Pulsar\Extension\Analytics\Domain\DeviceInfo;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\GeoInfo;
use Pulsar\Extension\Analytics\Domain\GoalConversion;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Domain\HourlyStats;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Domain\ReferrerSource;
use Pulsar\Extension\Analytics\Domain\Session;
use Pulsar\Extension\Analytics\Domain\Site;

#[CoversClass(CustomEvent::class)]
#[CoversClass(DailyStats::class)]
#[CoversClass(DeviceInfo::class)]
#[CoversClass(DeviceType::class)]
#[CoversClass(GeoInfo::class)]
#[CoversClass(GoalConversion::class)]
#[CoversClass(GoalType::class)]
#[CoversClass(HourlyStats::class)]
#[CoversClass(PageView::class)]
#[CoversClass(ReferrerSource::class)]
#[CoversClass(Session::class)]
#[CoversClass(Site::class)]
#[CoversClass(BreakdownDimension::class)]
final class DomainObjectsTest extends TestCase
{
    // --- PageView ---

    #[Test]
    public function pageViewConstruction(): void
    {
        $now = new DateTimeImmutable('2026-03-07 12:00:00');
        $pv = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'v-1',
            sessionId: 's-1',
            pathname: '/about',
            referrerSource: 'google',
            utmSource: 'newsletter',
            utmMedium: 'email',
            utmCampaign: 'spring',
            utmTerm: 'framework',
            utmContent: 'cta',
            countryCode: 'US',
            deviceType: DeviceType::Desktop,
            browser: 'Chrome',
            os: 'Windows',
            screenWidth: 1920,
            isBounce: false,
            createdAt: $now,
        );

        self::assertSame('pv-1', $pv->id);
        self::assertSame('site-1', $pv->siteId);
        self::assertSame('/about', $pv->pathname);
        self::assertSame('google', $pv->referrerSource);
        self::assertSame('newsletter', $pv->utmSource);
        self::assertSame('email', $pv->utmMedium);
        self::assertSame('spring', $pv->utmCampaign);
        self::assertSame('framework', $pv->utmTerm);
        self::assertSame('cta', $pv->utmContent);
        self::assertSame('US', $pv->countryCode);
        self::assertSame(DeviceType::Desktop, $pv->deviceType);
        self::assertSame('Chrome', $pv->browser);
        self::assertSame('Windows', $pv->os);
        self::assertSame(1920, $pv->screenWidth);
        self::assertFalse($pv->isBounce);
        self::assertSame($now, $pv->createdAt);
    }

    #[Test]
    public function pageViewDefaults(): void
    {
        $pv = new PageView(id: 'pv-1', siteId: 's', visitorId: 'v', sessionId: 's', pathname: '/');

        self::assertSame('', $pv->referrerSource);
        self::assertSame('', $pv->utmSource);
        self::assertSame('', $pv->utmMedium);
        self::assertSame('', $pv->utmCampaign);
        self::assertSame('', $pv->utmTerm);
        self::assertSame('', $pv->utmContent);
        self::assertSame('', $pv->countryCode);
        self::assertSame(DeviceType::Unknown, $pv->deviceType);
        self::assertSame('', $pv->browser);
        self::assertSame('', $pv->os);
        self::assertSame(0, $pv->screenWidth);
        self::assertTrue($pv->isBounce);
    }

    // --- Session ---

    #[Test]
    public function sessionConstruction(): void
    {
        $start = new DateTimeImmutable('2026-03-07 10:00:00');
        $session = new Session(
            id: 'sess-1',
            siteId: 'site-1',
            visitorId: 'v-1',
            sessionId: 'sid-1',
            entryPage: '/home',
            exitPage: '/home',
            startedAt: $start,
            endedAt: $start,
        );

        self::assertSame('sess-1', $session->id);
        self::assertSame('/home', $session->entryPage);
        self::assertSame('/home', $session->exitPage);
        self::assertSame(1, $session->pageCount);
        self::assertSame(0, $session->durationSeconds);
        self::assertTrue($session->isBounce);
    }

    #[Test]
    public function sessionWithPageViewUpdatesCounters(): void
    {
        $start = new DateTimeImmutable('2026-03-07 10:00:00');
        $session = new Session(
            id: 'sess-1',
            siteId: 'site-1',
            visitorId: 'v-1',
            sessionId: 'sid-1',
            entryPage: '/home',
            exitPage: '/home',
            startedAt: $start,
            endedAt: $start,
        );

        $later = new DateTimeImmutable('2026-03-07 10:05:00');
        $updated = $session->withPageView('/about', $later);

        self::assertSame('/about', $updated->exitPage);
        self::assertSame(2, $updated->pageCount);
        self::assertSame(300, $updated->durationSeconds);
        self::assertFalse($updated->isBounce);
        self::assertSame($later, $updated->endedAt);
        self::assertSame($start, $updated->startedAt);
    }

    #[Test]
    public function sessionWithPageViewClampsNegativeDuration(): void
    {
        $start = new DateTimeImmutable('2026-03-07 10:05:00');
        $session = new Session(
            id: 's',
            siteId: 's',
            visitorId: 'v',
            sessionId: 'sid',
            entryPage: '/',
            exitPage: '/',
            startedAt: $start,
            endedAt: $start,
        );

        $earlier = new DateTimeImmutable('2026-03-07 10:00:00');
        $updated = $session->withPageView('/x', $earlier);

        self::assertSame(0, $updated->durationSeconds);
    }

    // --- CustomEvent ---

    #[Test]
    public function customEventConstruction(): void
    {
        $event = new CustomEvent(
            id: 'e-1',
            siteId: 'site-1',
            visitorId: 'v-1',
            sessionId: 's-1',
            eventName: 'click_cta',
            eventProps: ['button' => 'signup'],
            revenueValue: 9.99,
            pathname: '/pricing',
        );

        self::assertSame('e-1', $event->id);
        self::assertSame('click_cta', $event->eventName);
        self::assertSame(['button' => 'signup'], $event->eventProps);
        self::assertSame(9.99, $event->revenueValue);
        self::assertSame('/pricing', $event->pathname);
    }

    #[Test]
    public function customEventDefaults(): void
    {
        $event = new CustomEvent(
            id: 'e-1',
            siteId: 's',
            visitorId: 'v',
            sessionId: 's',
            eventName: 'test',
        );

        self::assertSame([], $event->eventProps);
        self::assertNull($event->revenueValue);
        self::assertSame('', $event->pathname);
    }

    // --- DailyStats ---

    #[Test]
    public function dailyStatsConstruction(): void
    {
        $date = new DateTimeImmutable('2026-03-07');
        $stats = new DailyStats(
            siteId: 'site-1',
            date: $date,
            visitors: 150,
            pageviews: 400,
            sessions: 170,
            bounceRate: 45.5,
            avgDuration: 120.0,
            eventsCount: 30,
        );

        self::assertSame('site-1', $stats->siteId);
        self::assertSame($date, $stats->date);
        self::assertSame(150, $stats->visitors);
        self::assertSame(400, $stats->pageviews);
        self::assertSame(170, $stats->sessions);
        self::assertSame(45.5, $stats->bounceRate);
        self::assertSame(120.0, $stats->avgDuration);
        self::assertSame(30, $stats->eventsCount);
    }

    #[Test]
    public function dailyStatsDefaults(): void
    {
        $stats = new DailyStats(siteId: 's', date: new DateTimeImmutable());

        self::assertSame(0, $stats->visitors);
        self::assertSame(0, $stats->pageviews);
        self::assertSame(0, $stats->sessions);
        self::assertSame(0.0, $stats->bounceRate);
        self::assertSame(0.0, $stats->avgDuration);
        self::assertSame(0, $stats->eventsCount);
    }

    // --- HourlyStats ---

    #[Test]
    public function hourlyStatsConstruction(): void
    {
        $date = new DateTimeImmutable('2026-03-07');
        $stats = new HourlyStats(
            siteId: 'site-1',
            date: $date,
            hour: 14,
            visitors: 25,
            pageviews: 60,
            sessions: 28,
            bounceRate: 50.0,
            avgDuration: 90.0,
            eventsCount: 5,
        );

        self::assertSame(14, $stats->hour);
        self::assertSame(25, $stats->visitors);
    }

    // --- GoalConversion ---

    #[Test]
    public function goalConversionConstruction(): void
    {
        $conversion = new GoalConversion(
            id: 'gc-1',
            goalId: 'g-1',
            siteId: 's-1',
            visitorId: 'v-1',
            sessionId: 'ses-1',
            revenueValue: 49.99,
        );

        self::assertSame('gc-1', $conversion->id);
        self::assertSame('g-1', $conversion->goalId);
        self::assertSame(49.99, $conversion->revenueValue);
    }

    #[Test]
    public function goalConversionDefaultRevenue(): void
    {
        $conversion = new GoalConversion(
            id: 'gc-1',
            goalId: 'g-1',
            siteId: 's-1',
            visitorId: 'v-1',
            sessionId: 'ses-1',
        );

        self::assertNull($conversion->revenueValue);
    }

    // --- Site ---

    #[Test]
    public function siteConstruction(): void
    {
        $site = new Site(
            id: 'site-1',
            domain: 'example.com',
            name: 'Example',
            trackingId: 'plsr_abc123',
            timezone: 'America/New_York',
            settings: ['custom_scripts' => true],
        );

        self::assertSame('site-1', $site->id);
        self::assertSame('example.com', $site->domain);
        self::assertSame('Example', $site->name);
        self::assertSame('plsr_abc123', $site->trackingId);
        self::assertSame('America/New_York', $site->timezone);
        self::assertSame(['custom_scripts' => true], $site->settings);
    }

    #[Test]
    public function siteDefaults(): void
    {
        $site = new Site(id: 's', domain: 'd', name: 'n', trackingId: 't');

        self::assertSame('UTC', $site->timezone);
        self::assertSame([], $site->settings);
    }

    // --- DeviceInfo ---

    #[Test]
    public function deviceInfoConstruction(): void
    {
        $info = new DeviceInfo(
            browser: 'Firefox',
            browserVersion: '120.0',
            os: 'Linux',
            osVersion: '6.5',
            deviceType: DeviceType::Desktop,
        );

        self::assertSame('Firefox', $info->browser);
        self::assertSame('120.0', $info->browserVersion);
        self::assertSame('Linux', $info->os);
        self::assertSame('6.5', $info->osVersion);
        self::assertSame(DeviceType::Desktop, $info->deviceType);
    }

    #[Test]
    public function deviceInfoUnknownFactory(): void
    {
        $info = DeviceInfo::unknown();

        self::assertSame('Unknown', $info->browser);
        self::assertSame('', $info->browserVersion);
        self::assertSame('Unknown', $info->os);
        self::assertSame('', $info->osVersion);
        self::assertSame(DeviceType::Unknown, $info->deviceType);
    }

    // --- GeoInfo ---

    #[Test]
    public function geoInfoConstruction(): void
    {
        $geo = new GeoInfo(countryCode: 'FR', region: 'Ile-de-France');

        self::assertSame('FR', $geo->countryCode);
        self::assertSame('Ile-de-France', $geo->region);
    }

    #[Test]
    public function geoInfoDefaults(): void
    {
        $geo = new GeoInfo(countryCode: 'US');

        self::assertSame('', $geo->region);
    }

    #[Test]
    public function geoInfoUnknownFactory(): void
    {
        $geo = GeoInfo::unknown();

        self::assertSame('XX', $geo->countryCode);
        self::assertSame('', $geo->region);
    }

    // --- ReferrerSource ---

    #[Test]
    public function referrerSourceDirectFactory(): void
    {
        $ref = ReferrerSource::direct();

        self::assertSame('Direct / None', $ref->source);
        self::assertSame('none', $ref->medium);
        self::assertSame('', $ref->campaign);
        self::assertSame('', $ref->rawUrl);
        self::assertTrue($ref->isDirect());
    }

    #[Test]
    public function referrerSourceOrganicFactory(): void
    {
        $ref = ReferrerSource::fromOrganic('Google', 'https://google.com/search?q=test');

        self::assertSame('Google', $ref->source);
        self::assertSame('organic', $ref->medium);
        self::assertSame('https://google.com/search?q=test', $ref->rawUrl);
        self::assertFalse($ref->isDirect());
    }

    #[Test]
    public function referrerSourceSocialFactory(): void
    {
        $ref = ReferrerSource::fromSocial('Twitter', 'https://twitter.com/post/123');

        self::assertSame('Twitter', $ref->source);
        self::assertSame('social', $ref->medium);
        self::assertSame('https://twitter.com/post/123', $ref->rawUrl);
    }

    #[Test]
    public function referrerSourceReferralFactory(): void
    {
        $ref = ReferrerSource::fromReferral('blog.example.com', 'https://blog.example.com/article');

        self::assertSame('blog.example.com', $ref->source);
        self::assertSame('referral', $ref->medium);
    }

    #[Test]
    public function referrerSourceUtmFactory(): void
    {
        $ref = ReferrerSource::fromUtm('newsletter', 'email', 'spring_sale', 'https://example.com?utm_source=newsletter');

        self::assertSame('newsletter', $ref->source);
        self::assertSame('email', $ref->medium);
        self::assertSame('spring_sale', $ref->campaign);
    }

    // --- Enums ---

    #[Test]
    public function deviceTypeCases(): void
    {
        self::assertSame('desktop', DeviceType::Desktop->value);
        self::assertSame('mobile', DeviceType::Mobile->value);
        self::assertSame('tablet', DeviceType::Tablet->value);
        self::assertSame('unknown', DeviceType::Unknown->value);
        self::assertCount(4, DeviceType::cases());
    }

    #[Test]
    public function goalTypeCases(): void
    {
        self::assertSame('page_visit', GoalType::PageVisit->value);
        self::assertSame('custom_event', GoalType::CustomEvent->value);
        self::assertCount(2, GoalType::cases());
    }

    #[Test]
    public function breakdownDimensionCases(): void
    {
        self::assertSame('page', BreakdownDimension::Page->value);
        self::assertSame('referrer', BreakdownDimension::Referrer->value);
        self::assertSame('country', BreakdownDimension::Country->value);
        self::assertSame('browser', BreakdownDimension::Browser->value);
        self::assertSame('os', BreakdownDimension::Os->value);
        self::assertSame('device', BreakdownDimension::Device->value);
        self::assertSame('utm_source', BreakdownDimension::UtmSource->value);
        self::assertSame('utm_medium', BreakdownDimension::UtmMedium->value);
        self::assertSame('utm_campaign', BreakdownDimension::UtmCampaign->value);
        self::assertCount(9, BreakdownDimension::cases());
    }
}
