<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\PageView;

final class PageViewTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');

        $pv = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            pathname: '/about',
            referrerSource: 'Google',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'spring-sale',
            utmTerm: 'analytics',
            utmContent: 'banner',
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
        self::assertSame('visitor-1', $pv->visitorId);
        self::assertSame('session-1', $pv->sessionId);
        self::assertSame('/about', $pv->pathname);
        self::assertSame('Google', $pv->referrerSource);
        self::assertSame('google', $pv->utmSource);
        self::assertSame('cpc', $pv->utmMedium);
        self::assertSame('spring-sale', $pv->utmCampaign);
        self::assertSame('analytics', $pv->utmTerm);
        self::assertSame('banner', $pv->utmContent);
        self::assertSame('US', $pv->countryCode);
        self::assertSame(DeviceType::Desktop, $pv->deviceType);
        self::assertSame('Chrome', $pv->browser);
        self::assertSame('Windows', $pv->os);
        self::assertSame(1920, $pv->screenWidth);
        self::assertFalse($pv->isBounce);
        self::assertSame($now, $pv->createdAt);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $pv = new PageView(
            id: 'pv-2',
            siteId: 'site-1',
            visitorId: 'visitor-1',
            sessionId: 'session-1',
            pathname: '/',
        );

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
}
