<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;
use Pulsar\Extension\Analytics\Domain\DeviceType;
use Pulsar\Extension\Analytics\Domain\PageView;
use Pulsar\Extension\Analytics\Domain\Session;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarCollector;

#[CoversClass(AnalyticsDsarCollector::class)]
final class AnalyticsDsarCollectorTest extends TestCase
{
    private PageViewRepositoryInterface&Stub $pageViewRepo;
    private SessionRepositoryInterface&Stub $sessionRepo;
    private EventRepositoryInterface&Stub $eventRepo;
    private AnalyticsDsarCollector $collector;

    protected function setUp(): void
    {
        $this->pageViewRepo = $this->createStub(PageViewRepositoryInterface::class);
        $this->sessionRepo = $this->createStub(SessionRepositoryInterface::class);
        $this->eventRepo = $this->createStub(EventRepositoryInterface::class);

        $this->collector = new AnalyticsDsarCollector(
            $this->pageViewRepo,
            $this->sessionRepo,
            $this->eventRepo,
        );
    }

    #[Test]
    public function sourceNameReturnsAnalytics(): void
    {
        self::assertSame('analytics', $this->collector->sourceName());
    }

    #[Test]
    public function collectReturnsEmptyDataSetWhenNoRecordsExist(): void
    {
        $this->pageViewRepo->method('findByVisitorId')->willReturn([]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('visitor-hash-123');

        self::assertSame('analytics', $dataSet->sourceName);
        self::assertSame('analytics', $dataSet->category);
        self::assertSame([], $dataSet->records);
    }

    #[Test]
    public function collectIncludesPageViewRecords(): void
    {
        $createdAt = new DateTimeImmutable('2026-03-20 10:00:00');
        $pageView = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'visitor-hash-123',
            sessionId: 'sess-1',
            pathname: '/about',
            referrerSource: 'google',
            countryCode: 'US',
            deviceType: DeviceType::Desktop,
            browser: 'Firefox',
            os: 'Linux',
            screenWidth: 1920,
            isBounce: false,
            createdAt: $createdAt,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([$pageView]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('visitor-hash-123');

        self::assertCount(1, $dataSet->records);
        self::assertSame('page_view', $dataSet->records[0]['type']);
        self::assertSame('pv-1', $dataSet->records[0]['id']);
        self::assertSame('/about', $dataSet->records[0]['pathname']);
        self::assertSame('google', $dataSet->records[0]['referrer_source']);
        self::assertSame('US', $dataSet->records[0]['country_code']);
        self::assertSame('desktop', $dataSet->records[0]['device_type']);
        self::assertSame('Firefox', $dataSet->records[0]['browser']);
        self::assertSame('Linux', $dataSet->records[0]['os']);
        self::assertSame(1920, $dataSet->records[0]['screen_width']);
        self::assertFalse($dataSet->records[0]['is_bounce']);
    }

    #[Test]
    public function collectIncludesSessionRecords(): void
    {
        $startedAt = new DateTimeImmutable('2026-03-20 09:00:00');
        $endedAt = new DateTimeImmutable('2026-03-20 09:15:00');
        $session = new Session(
            id: 'rec-1',
            siteId: 'site-1',
            visitorId: 'visitor-hash-123',
            sessionId: 'sess-1',
            entryPage: '/home',
            exitPage: '/contact',
            pageCount: 3,
            durationSeconds: 900,
            isBounce: false,
            startedAt: $startedAt,
            endedAt: $endedAt,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([$session]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('visitor-hash-123');

        self::assertCount(1, $dataSet->records);
        self::assertSame('session', $dataSet->records[0]['type']);
        self::assertSame('rec-1', $dataSet->records[0]['id']);
        self::assertSame('/home', $dataSet->records[0]['entry_page']);
        self::assertSame('/contact', $dataSet->records[0]['exit_page']);
        self::assertSame(3, $dataSet->records[0]['page_count']);
        self::assertSame(900, $dataSet->records[0]['duration_seconds']);
        self::assertFalse($dataSet->records[0]['is_bounce']);
    }

    #[Test]
    public function collectIncludesCustomEventRecords(): void
    {
        $createdAt = new DateTimeImmutable('2026-03-20 11:00:00');
        $event = new CustomEvent(
            id: 'evt-1',
            siteId: 'site-1',
            visitorId: 'visitor-hash-123',
            sessionId: 'sess-1',
            eventName: 'button_click',
            eventProps: ['button_id' => 'cta-main'],
            revenueValue: 49.99,
            pathname: '/pricing',
            createdAt: $createdAt,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([$event]);

        $dataSet = $this->collector->collect('visitor-hash-123');

        self::assertCount(1, $dataSet->records);
        self::assertSame('custom_event', $dataSet->records[0]['type']);
        self::assertSame('evt-1', $dataSet->records[0]['id']);
        self::assertSame('button_click', $dataSet->records[0]['event_name']);
        self::assertSame(['button_id' => 'cta-main'], $dataSet->records[0]['event_props']);
        self::assertSame(49.99, $dataSet->records[0]['revenue_value']);
        self::assertSame('/pricing', $dataSet->records[0]['pathname']);
    }

    #[Test]
    public function collectMergesAllDataTypesIntoSingleRecordSet(): void
    {
        $now = new DateTimeImmutable();

        $pageView = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            pathname: '/page',
            createdAt: $now,
        );

        $session = new Session(
            id: 'rec-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            entryPage: '/page',
            exitPage: '/page',
            startedAt: $now,
            endedAt: $now,
        );

        $event = new CustomEvent(
            id: 'evt-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            eventName: 'click',
            createdAt: $now,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([$pageView]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([$session]);
        $this->eventRepo->method('findByVisitorId')->willReturn([$event]);

        $dataSet = $this->collector->collect('v1');

        self::assertCount(3, $dataSet->records);
        self::assertSame('page_view', $dataSet->records[0]['type']);
        self::assertSame('session', $dataSet->records[1]['type']);
        self::assertSame('custom_event', $dataSet->records[2]['type']);
    }

    #[Test]
    public function collectHandlesMultipleRecordsOfSameType(): void
    {
        $now = new DateTimeImmutable();
        $pageViews = [];

        for ($i = 1; $i <= 5; $i++) {
            $pageViews[] = new PageView(
                id: "pv-{$i}",
                siteId: 'site-1',
                visitorId: 'v1',
                sessionId: 's1',
                pathname: "/page-{$i}",
                createdAt: $now,
            );
        }

        $this->pageViewRepo->method('findByVisitorId')->willReturn($pageViews);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('v1');

        self::assertCount(5, $dataSet->records);

        foreach ($dataSet->records as $i => $record) {
            self::assertSame('page_view', $record['type']);
            self::assertSame('pv-' . ($i + 1), $record['id']);
        }
    }

    #[Test]
    public function collectExcludesVisitorIdFromRecords(): void
    {
        $now = new DateTimeImmutable();
        $pageView = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'sensitive-hash',
            sessionId: 's1',
            pathname: '/page',
            createdAt: $now,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([$pageView]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('sensitive-hash');

        // visitor_id and session_id should not be in the serialized output
        self::assertArrayNotHasKey('visitor_id', $dataSet->records[0]);
        self::assertArrayNotHasKey('session_id', $dataSet->records[0]);
    }

    #[Test]
    public function collectSerializesDateTimesInIso8601Format(): void
    {
        $createdAt = new DateTimeImmutable('2026-03-20T10:30:00+00:00');
        $pageView = new PageView(
            id: 'pv-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            pathname: '/page',
            createdAt: $createdAt,
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([$pageView]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([]);

        $dataSet = $this->collector->collect('v1');

        $createdAt = $dataSet->records[0]['created_at'];
        self::assertIsString($createdAt);
        self::assertStringContainsString('2026-03-20', $createdAt);
    }

    #[Test]
    public function collectHandlesEventWithNullRevenue(): void
    {
        $event = new CustomEvent(
            id: 'evt-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            eventName: 'pageview',
            revenueValue: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([$event]);

        $dataSet = $this->collector->collect('v1');

        self::assertNull($dataSet->records[0]['revenue_value']);
    }

    #[Test]
    public function collectHandlesEventWithEmptyProps(): void
    {
        $event = new CustomEvent(
            id: 'evt-1',
            siteId: 'site-1',
            visitorId: 'v1',
            sessionId: 's1',
            eventName: 'scroll',
            eventProps: [],
            createdAt: new DateTimeImmutable(),
        );

        $this->pageViewRepo->method('findByVisitorId')->willReturn([]);
        $this->sessionRepo->method('findByVisitorId')->willReturn([]);
        $this->eventRepo->method('findByVisitorId')->willReturn([$event]);

        $dataSet = $this->collector->collect('v1');

        self::assertSame([], $dataSet->records[0]['event_props']);
    }
}
