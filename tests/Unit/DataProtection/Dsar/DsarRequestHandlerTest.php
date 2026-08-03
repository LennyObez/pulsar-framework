<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarCollectorInterface;
use Pulsar\DataProtection\Dsar\DsarDataSet;
use Pulsar\DataProtection\Dsar\DsarPackager;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarRequestHandler;
use Pulsar\DataProtection\Dsar\DsarStatus;
use Pulsar\DataProtection\Dsar\DsarStoreInterface;
use RuntimeException;

use function strlen;

#[CoversClass(DsarRequestHandler::class)]
final class DsarRequestHandlerTest extends TestCase
{
    #[Test]
    public function submitCreatesRequestWithPendingStatus(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $request = $handler->submit('user-123', 'user@example.com');

        self::assertSame(DsarStatus::Pending, $request->status);
        self::assertSame('user-123', $request->subjectId);
        self::assertSame('user@example.com', $request->email);
    }

    #[Test]
    public function submitGeneratesUniqueId(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $req1 = $handler->submit('user-1', 'a@b.com');
        $req2 = $handler->submit('user-2', 'c@d.com');

        self::assertNotSame($req1->id, $req2->id);
        self::assertNotEmpty($req1->id);
        self::assertNotEmpty($req2->id);
    }

    #[Test]
    public function submitSetsThirtyDayDeadline(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $request = $handler->submit('user-1', 'a@b.com');

        $remaining = $request->remainingDays();
        self::assertGreaterThanOrEqual(29, $remaining);
        self::assertLessThanOrEqual(31, $remaining);
    }

    #[Test]
    public function submitGeneratesVerificationToken(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $request = $handler->submit('user-1', 'a@b.com');

        self::assertNotNull($request->verificationToken);
        self::assertSame(64, strlen($request->verificationToken)); // 32 bytes hex-encoded = 64 chars
    }

    #[Test]
    public function submitPersistsToStore(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $request = $handler->submit('user-1', 'a@b.com');
        $found = $store->findById($request->id);

        self::assertNotNull($found);
        self::assertSame($request->id, $found->id);
    }

    #[Test]
    public function processCollectsDataAndBuildsPackage(): void
    {
        $collector = $this->createStub(DsarCollectorInterface::class);
        $collector->method('collect')->willReturn(
            new DsarDataSet('auth', 'profile', [['email' => 'user@example.com']]),
        );

        $store = $this->createInMemoryStore();

        $handler = new DsarRequestHandler(
            [$collector],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $submitted = $handler->submit('user-1', 'user@example.com');
        $processed = $handler->process($submitted->id);

        self::assertSame(DsarStatus::Completed, $processed->status);
        self::assertNotNull($processed->packagePath);
        self::assertNotNull($processed->completedAt);
        self::assertFileExists($processed->packagePath);
    }

    #[Test]
    public function processThrowsForNonExistentRequest(): void
    {
        $store = $this->createInMemoryStore();
        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('DSAR request not found');

        $handler->process('nonexistent-id');
    }

    #[Test]
    public function getOverdueRequestsReturnsOnlyOverdue(): void
    {
        $store = $this->createInMemoryStore();

        // Overdue
        $store->save(new DsarRequest(
            id: 'overdue-1',
            subjectId: 's1',
            email: 'a@b.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        ));

        // On track
        $store->save(new DsarRequest(
            id: 'ontrack-1',
            subjectId: 's2',
            email: 'b@c.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-5 days'),
            deadline: new DateTimeImmutable('+25 days'),
        ));

        // Completed (past deadline but not overdue)
        $store->save(new DsarRequest(
            id: 'completed-1',
            subjectId: 's3',
            email: 'c@d.com',
            status: DsarStatus::Completed,
            createdAt: new DateTimeImmutable('-35 days'),
            deadline: new DateTimeImmutable('-5 days'),
        ));

        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $overdue = $handler->getOverdueRequests();

        self::assertCount(1, $overdue);
        self::assertSame('overdue-1', $overdue[0]->id);
    }

    #[Test]
    public function findRequestDelegatesToStore(): void
    {
        $store = $this->createInMemoryStore();
        $store->save(new DsarRequest(
            id: 'req-find',
            subjectId: 'sub-1',
            email: 'a@b.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable(),
            deadline: new DateTimeImmutable('+30 days'),
        ));

        $handler = new DsarRequestHandler(
            [],
            new DsarPackager(sys_get_temp_dir()),
            $store,
        );

        $found = $handler->findRequest('req-find');
        self::assertNotNull($found);
        self::assertSame('req-find', $found->id);

        $notFound = $handler->findRequest('does-not-exist');
        self::assertNull($notFound);
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
