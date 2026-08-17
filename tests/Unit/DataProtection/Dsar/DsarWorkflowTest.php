<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarAttachment;
use Pulsar\DataProtection\Dsar\DsarCollectorInterface;
use Pulsar\DataProtection\Dsar\DsarDataSet;
use Pulsar\DataProtection\Dsar\DsarDeadlineReport;
use Pulsar\DataProtection\Dsar\DsarDeadlineTracker;
use Pulsar\DataProtection\Dsar\DsarPackager;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarRequestHandler;
use Pulsar\DataProtection\Dsar\DsarStatus;
use Pulsar\DataProtection\Dsar\DsarStoreInterface;

#[CoversClass(DsarRequest::class)]
#[CoversClass(DsarDataSet::class)]
#[CoversClass(DsarAttachment::class)]
#[CoversClass(DsarDeadlineReport::class)]
#[CoversClass(DsarDeadlineTracker::class)]
#[CoversClass(DsarRequestHandler::class)]
final class DsarWorkflowTest extends TestCase
{
    #[Test]
    public function requestCalculatesRemainingDays(): void
    {
        $request = new DsarRequest(
            id: 'req-1',
            subjectId: 'sub-1',
            email: 'user@example.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-5 days'),
            deadline: new DateTimeImmutable('+25 days'),
        );

        $remaining = $request->remainingDays();

        self::assertGreaterThanOrEqual(24, $remaining);
        self::assertLessThanOrEqual(26, $remaining);
    }

    #[Test]
    public function requestIsOverdueWhenDeadlinePassed(): void
    {
        $request = new DsarRequest(
            id: 'req-1',
            subjectId: 'sub-1',
            email: 'user@example.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-35 days'),
            deadline: new DateTimeImmutable('-5 days'),
        );

        self::assertTrue($request->isOverdue());
    }

    #[Test]
    public function completedRequestIsNeverOverdue(): void
    {
        $request = new DsarRequest(
            id: 'req-1',
            subjectId: 'sub-1',
            email: 'user@example.com',
            status: DsarStatus::Completed,
            createdAt: new DateTimeImmutable('-35 days'),
            deadline: new DateTimeImmutable('-5 days'),
            completedAt: new DateTimeImmutable('-6 days'),
        );

        self::assertFalse($request->isOverdue());
    }

    #[Test]
    public function statusEnumHasAllExpectedValues(): void
    {
        self::assertSame('pending', DsarStatus::Pending->value);
        self::assertSame('processing', DsarStatus::Processing->value);
        self::assertSame('completed', DsarStatus::Completed->value);
        self::assertSame('rejected', DsarStatus::Rejected->value);
        self::assertSame('downloaded', DsarStatus::Downloaded->value);
    }

    #[Test]
    public function dataSetFactoryCreatesEmptySet(): void
    {
        $set = DsarDataSet::empty('audit', 'logs');

        self::assertSame('audit', $set->sourceName);
        self::assertSame('logs', $set->category);
        self::assertSame([], $set->records);
        self::assertSame([], $set->attachments);
    }

    #[Test]
    public function attachmentHoldsAllProperties(): void
    {
        $attachment = new DsarAttachment('photo.jpg', 'binary-data', 'image/jpeg');

        self::assertSame('photo.jpg', $attachment->filename);
        self::assertSame('binary-data', $attachment->content);
        self::assertSame('image/jpeg', $attachment->mimeType);
    }

    #[Test]
    public function deadlineReportIdentifiesOverdueRequests(): void
    {
        $overdue = [new DsarRequest(
            id: 'r1',
            subjectId: 's1',
            email: 'a@b.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        )];

        $report = new DsarDeadlineReport($overdue, [], []);

        self::assertTrue($report->hasOverdue());
        self::assertFalse($report->isCompliant());
    }

    #[Test]
    public function deadlineReportIsCompliantWithNoOverdue(): void
    {
        $report = new DsarDeadlineReport([], [], []);

        self::assertFalse($report->hasOverdue());
        self::assertTrue($report->isCompliant());
    }

    #[Test]
    public function handlerSubmitsRequestWithThirtyDayDeadline(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $request = $handler->submit('sub-1', 'user@example.com');

        self::assertSame(DsarStatus::Pending, $request->status);
        self::assertSame('sub-1', $request->subjectId);
        self::assertSame('user@example.com', $request->email);
        self::assertNotNull($request->verificationToken);

        $remaining = $request->remainingDays();
        self::assertGreaterThanOrEqual(29, $remaining);
        self::assertLessThanOrEqual(31, $remaining);
    }

    #[Test]
    public function deadlineTrackerCategorizesByDeadlineProximity(): void
    {
        $store = $this->createInMemoryStore();

        // Overdue request
        $store->save(new DsarRequest(
            id: 'overdue',
            subjectId: 's1',
            email: 'a@b.com',
            status: DsarStatus::Processing,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        ));

        // At-risk request (3 days remaining)
        $store->save(new DsarRequest(
            id: 'at-risk',
            subjectId: 's2',
            email: 'b@c.com',
            status: DsarStatus::Processing,
            createdAt: new DateTimeImmutable('-27 days'),
            deadline: new DateTimeImmutable('+3 days'),
        ));

        // On-track request (20 days remaining)
        $store->save(new DsarRequest(
            id: 'on-track',
            subjectId: 's3',
            email: 'c@d.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-10 days'),
            deadline: new DateTimeImmutable('+20 days'),
        ));

        // Completed (should be excluded)
        $store->save(new DsarRequest(
            id: 'done',
            subjectId: 's4',
            email: 'd@e.com',
            status: DsarStatus::Completed,
            createdAt: new DateTimeImmutable('-35 days'),
            deadline: new DateTimeImmutable('-5 days'),
        ));

        $tracker = new DsarDeadlineTracker($store);
        $report = $tracker->check();

        self::assertCount(1, $report->overdue);
        self::assertSame('overdue', $report->overdue[0]->id);

        self::assertCount(1, $report->atRisk);
        self::assertSame('at-risk', $report->atRisk[0]->id);

        self::assertCount(1, $report->onTrack);
        self::assertSame('on-track', $report->onTrack[0]->id);
    }

    #[Test]
    public function handlerCollectsFromAllCollectors(): void
    {
        $collector1 = $this->createStub(DsarCollectorInterface::class);
        $collector1->method('collect')->willReturn(
            new DsarDataSet('auth', 'profile', [['email' => 'user@example.com']]),
        );
        $collector1->method('sourceName')->willReturn('auth');

        $collector2 = $this->createStub(DsarCollectorInterface::class);
        $collector2->method('collect')->willReturn(
            DsarDataSet::empty('analytics', 'events'),
        );
        $collector2->method('sourceName')->willReturn('analytics');

        $outputDir = sys_get_temp_dir() . '/dsar-test-' . bin2hex(random_bytes(4));
        $store = $this->createInMemoryStore();

        $handler = new DsarRequestHandler(
            [$collector1, $collector2],
            new DsarPackager($outputDir),
            $store,
        );

        $submitted = $handler->submit('sub-1', 'user@example.com');
        $processed = $handler->process($submitted->id);

        self::assertSame(DsarStatus::Completed, $processed->status);
        self::assertNotNull($processed->packagePath);
        self::assertNotNull($processed->completedAt);

        // Cleanup
        if (is_file($processed->packagePath)) {
            $realPath = realpath($processed->packagePath);
            $tempBase = realpath(sys_get_temp_dir());
            if ($realPath !== false && $tempBase !== false && str_starts_with($realPath, $tempBase)) {
                @unlink($realPath);
            }
        }
        if (is_dir($outputDir)) {
            @rmdir($outputDir);
        }
    }

    private function createInMemoryStore(): DsarStoreInterface
    {
        return new class implements DsarStoreInterface {
            /** @var array<string, DsarRequest> */
            private array $requests = [];

            public function save(DsarRequest $request): void
            {
                $this->requests[$request->id] = $request;
            }

            public function findById(string $id): ?DsarRequest
            {
                return $this->requests[$id] ?? null;
            }

            public function findBySubject(string $subjectId): ?DsarRequest
            {
                foreach ($this->requests as $r) {
                    if ($r->subjectId === $subjectId) {
                        return $r;
                    }
                }
                return null;
            }

            /** @return list<DsarRequest> */
            public function findAll(): array
            {
                return array_values($this->requests);
            }
        };
    }
}
