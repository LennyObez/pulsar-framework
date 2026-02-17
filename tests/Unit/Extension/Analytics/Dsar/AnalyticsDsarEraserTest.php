<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarEraser;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AnalyticsDsarEraser::class)]
final class AnalyticsDsarEraserTest extends TestCase
{
    private PageViewRepositoryInterface&Stub $pageViewRepo;
    private SessionRepositoryInterface&Stub $sessionRepo;
    private EventRepositoryInterface&Stub $eventRepo;

    protected function setUp(): void
    {
        $this->pageViewRepo = $this->createStub(PageViewRepositoryInterface::class);
        $this->sessionRepo = $this->createStub(SessionRepositoryInterface::class);
        $this->eventRepo = $this->createStub(EventRepositoryInterface::class);
    }

    private static function dummyAuditEntry(): AuditEntry
    {
        return new AuditEntry(
            id: 'test-entry-id',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: '',
            action: 'test',
            resource: 'test',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            hmac: 'dummy-hmac',
        );
    }

    private function createEraserWithStubLogger(): AnalyticsDsarEraser
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn(self::dummyAuditEntry());

        return new AnalyticsDsarEraser(
            $this->pageViewRepo,
            $this->sessionRepo,
            $this->eventRepo,
            $auditLogger,
        );
    }

    /**
     * Create an eraser with a mock audit logger for verification tests.
     *
     * @return array{AnalyticsDsarEraser, AuditLoggerInterface&MockObject}
     */
    private function createEraserWithMockLogger(): array
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn(self::dummyAuditEntry());

        $eraser = new AnalyticsDsarEraser(
            $this->pageViewRepo,
            $this->sessionRepo,
            $this->eventRepo,
            $auditLogger,
        );

        return [$eraser, $auditLogger];
    }

    #[Test]
    public function eraseReturnsTotalDeletedRowCount(): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn(15);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn(3);
        $this->eventRepo->method('deleteByVisitorId')->willReturn(7);

        $eraser = $this->createEraserWithStubLogger();
        $total = $eraser->erase('visitor-hash-abc');

        self::assertSame(25, $total);
    }

    #[Test]
    public function eraseReturnsZeroWhenNoRecordsExist(): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn(0);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn(0);
        $this->eventRepo->method('deleteByVisitorId')->willReturn(0);

        $eraser = $this->createEraserWithStubLogger();
        $total = $eraser->erase('nonexistent-visitor');

        self::assertSame(0, $total);
    }

    #[Test]
    public function eraseLogsAuditEventWithCorrectParameters(): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn(10);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn(2);
        $this->eventRepo->method('deleteByVisitorId')->willReturn(5);

        [$eraser, $auditLogger] = $this->createEraserWithMockLogger();

        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                null,
                'analytics.dsar.erasure',
                'analytics_visitor:visitor-hash-xyz',
                [
                    'page_views_deleted' => 10,
                    'sessions_deleted' => 2,
                    'events_deleted' => 5,
                    'total_deleted' => 17,
                ],
            )
            ->willReturn(self::dummyAuditEntry());

        $eraser->erase('visitor-hash-xyz');
    }

    #[Test]
    public function eraseLogsEvenWhenNothingDeleted(): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn(0);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn(0);
        $this->eventRepo->method('deleteByVisitorId')->willReturn(0);

        [$eraser, $auditLogger] = $this->createEraserWithMockLogger();

        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                null,
                'analytics.dsar.erasure',
                'analytics_visitor:empty-visitor',
                [
                    'page_views_deleted' => 0,
                    'sessions_deleted' => 0,
                    'events_deleted' => 0,
                    'total_deleted' => 0,
                ],
            )
            ->willReturn(self::dummyAuditEntry());

        $eraser->erase('empty-visitor');
    }

    #[Test]
    #[DataProvider('deletionCountsProvider')]
    public function eraseCorrectlySumsDeletedCounts(int $pageViews, int $sessions, int $events, int $expectedTotal): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn($pageViews);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn($sessions);
        $this->eventRepo->method('deleteByVisitorId')->willReturn($events);

        $eraser = $this->createEraserWithStubLogger();
        $total = $eraser->erase('v1');

        self::assertSame($expectedTotal, $total);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function deletionCountsProvider(): iterable
    {
        yield 'all zero' => [0, 0, 0, 0];
        yield 'only page views' => [100, 0, 0, 100];
        yield 'only sessions' => [0, 50, 0, 50];
        yield 'only events' => [0, 0, 25, 25];
        yield 'all repositories have data' => [200, 30, 75, 305];
        yield 'single record each' => [1, 1, 1, 3];
        yield 'large volume' => [50000, 5000, 10000, 65000];
    }

    #[Test]
    public function eraseIncludesVisitorIdInAuditResource(): void
    {
        $this->pageViewRepo->method('deleteByVisitorId')->willReturn(1);
        $this->sessionRepo->method('deleteByVisitorId')->willReturn(0);
        $this->eventRepo->method('deleteByVisitorId')->willReturn(0);

        $visitorId = 'a1b2c3d4e5f6';

        [$eraser, $auditLogger] = $this->createEraserWithMockLogger();

        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                'analytics_visitor:' . $visitorId,
                self::anything(),
            )
            ->willReturn(self::dummyAuditEntry());

        $eraser->erase($visitorId);
    }

    #[Test]
    public function eraseDeletesFromAllThreeRepositories(): void
    {
        // We use mocks here specifically because we need to verify
        // that deleteByVisitorId is called on each repository with
        // the correct visitor ID
        $pageViewRepo = $this->createMock(PageViewRepositoryInterface::class);
        $sessionRepo = $this->createMock(SessionRepositoryInterface::class);
        $eventRepo = $this->createMock(EventRepositoryInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn(self::dummyAuditEntry());

        $pageViewRepo->expects(self::once())
            ->method('deleteByVisitorId')
            ->with('target-visitor')
            ->willReturn(5);

        $sessionRepo->expects(self::once())
            ->method('deleteByVisitorId')
            ->with('target-visitor')
            ->willReturn(1);

        $eventRepo->expects(self::once())
            ->method('deleteByVisitorId')
            ->with('target-visitor')
            ->willReturn(3);

        $eraser = new AnalyticsDsarEraser(
            $pageViewRepo,
            $sessionRepo,
            $eventRepo,
            $auditLogger,
        );

        $total = $eraser->erase('target-visitor');

        self::assertSame(9, $total);
    }
}
