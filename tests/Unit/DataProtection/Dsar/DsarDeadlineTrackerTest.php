<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\DataProtection\Dsar\DsarDeadlineTracker;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarStatus;
use Pulsar\DataProtection\Dsar\DsarStoreInterface;

#[CoversClass(DsarDeadlineTracker::class)]
final class DsarDeadlineTrackerTest extends TestCase
{
    #[Test]
    public function overdueRequestsAreCategorizedCorrectly(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('overdue', DsarStatus::Processing, '-40 days', '-10 days'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(1, $report->overdue);
        self::assertSame('overdue', $report->overdue[0]->id);
        self::assertCount(0, $report->atRisk);
        self::assertCount(0, $report->onTrack);
    }

    #[Test]
    public function atRiskRequestsAreCategorizedCorrectly(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('at-risk', DsarStatus::Processing, '-27 days', '+3 days'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(1, $report->atRisk);
        self::assertSame('at-risk', $report->atRisk[0]->id);
        self::assertCount(0, $report->onTrack);
    }

    #[Test]
    public function onTrackRequestsAreCategorizedCorrectly(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('on-track', DsarStatus::Pending, '-5 days', '+20 days'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(0, $report->atRisk);
        self::assertCount(1, $report->onTrack);
        self::assertSame('on-track', $report->onTrack[0]->id);
    }

    #[Test]
    public function completedRequestsAreExcluded(): void
    {
        $store = $this->createStoreWith([
            new DsarRequest(
                id: 'done',
                subjectId: 's1',
                email: 'a@b.com',
                status: DsarStatus::Completed,
                createdAt: new DateTimeImmutable('-35 days'),
                deadline: new DateTimeImmutable('-5 days'),
                completedAt: new DateTimeImmutable('-6 days'),
            ),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(0, $report->atRisk);
        self::assertCount(0, $report->onTrack);
    }

    #[Test]
    public function rejectedRequestsAreExcluded(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('rejected', DsarStatus::Rejected, '-40 days', '-10 days'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(0, $report->atRisk);
        self::assertCount(0, $report->onTrack);
    }

    #[Test]
    public function downloadedRequestsAreExcluded(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('downloaded', DsarStatus::Downloaded, '-35 days', '-5 days'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(0, $report->atRisk);
        self::assertCount(0, $report->onTrack);
    }

    #[Test]
    public function loggerReceivesCriticalForOverdueRequests(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('overdue', DsarStatus::Pending, '-40 days', '-10 days'),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with('DSAR deadline exceeded', self::callback(
                static fn(array $ctx): bool => $ctx['request_id'] === 'overdue' && $ctx['days_overdue'] > 0,
            ));

        $tracker = new DsarDeadlineTracker($store, $logger);
        $report = $tracker->check();

        self::assertCount(1, $report->overdue);
    }

    #[Test]
    public function loggerReceivesWarningForAtRiskRequests(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('at-risk', DsarStatus::Processing, '-27 days', '+3 days'),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('DSAR deadline approaching', self::callback(
                static fn(array $ctx): bool => $ctx['request_id'] === 'at-risk' && $ctx['days_remaining'] >= 0,
            ));

        $tracker = new DsarDeadlineTracker($store, $logger);
        $report = $tracker->check();

        self::assertCount(1, $report->atRisk);
    }

    #[Test]
    public function emptyStoreProducesEmptyReport(): void
    {
        $store = $this->createStoreWith([]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(0, $report->overdue);
        self::assertCount(0, $report->atRisk);
        self::assertCount(0, $report->onTrack);
        self::assertTrue($report->isCompliant());
    }

    #[Test]
    public function mixedStatusesAreCategorizedCorrectly(): void
    {
        $store = $this->createStoreWith([
            $this->makeRequest('overdue', DsarStatus::Pending, '-40 days', '-10 days'),
            $this->makeRequest('at-risk', DsarStatus::Processing, '-27 days', '+3 days'),
            $this->makeRequest('on-track', DsarStatus::Pending, '-5 days', '+20 days'),
            $this->makeRequest('completed', DsarStatus::Completed, '-30 days', '-1 day'),
            $this->makeRequest('rejected', DsarStatus::Rejected, '-30 days', '-1 day'),
        ]);

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(1, $report->overdue);
        self::assertCount(1, $report->atRisk);
        self::assertCount(1, $report->onTrack);
    }

    private function makeRequest(
        string $id,
        DsarStatus $status,
        string $createdOffset,
        string $deadlineOffset,
    ): DsarRequest {
        return new DsarRequest(
            id: $id,
            subjectId: 'sub-' . $id,
            email: $id . '@example.com',
            status: $status,
            createdAt: new DateTimeImmutable($createdOffset),
            deadline: new DateTimeImmutable($deadlineOffset),
        );
    }

    /**
     * @param list<DsarRequest> $requests
     */
    private function createStoreWith(array $requests): DsarStoreInterface
    {
        $store = $this->createStub(DsarStoreInterface::class);
        $store->method('findAll')->willReturn($requests);

        return $store;
    }
}
