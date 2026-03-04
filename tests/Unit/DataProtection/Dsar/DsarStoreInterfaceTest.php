<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarStatus;
use Pulsar\DataProtection\Dsar\DsarStoreInterface;

/**
 * Tests the DsarStoreInterface contract via stubs.
 */
final class DsarStoreInterfaceTest extends TestCase
{
    #[Test]
    public function findByIdReturnsRequestWhenFound(): void
    {
        // Arrange
        $request = $this->createDsarRequest('dsar-1', 'subject-1');

        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findById')->willReturn($request);

        // Act
        $result = $stub->findById('dsar-1');

        // Assert
        self::assertNotNull($result);
        self::assertSame('dsar-1', $result->id);
        self::assertSame('subject-1', $result->subjectId);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        // Arrange
        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findById')->willReturn(null);

        // Act & Assert
        self::assertNull($stub->findById('nonexistent'));
    }

    #[Test]
    public function findBySubjectReturnsRequestForKnownSubject(): void
    {
        // Arrange
        $request = $this->createDsarRequest('dsar-2', 'user-42');

        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findBySubject')->willReturn($request);

        // Act
        $result = $stub->findBySubject('user-42');

        // Assert
        self::assertNotNull($result);
        self::assertSame('user-42', $result->subjectId);
    }

    #[Test]
    public function findBySubjectReturnsNullForUnknownSubject(): void
    {
        // Arrange
        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findBySubject')->willReturn(null);

        // Act & Assert
        self::assertNull($stub->findBySubject('unknown'));
    }

    #[Test]
    public function findAllReturnsAllRequests(): void
    {
        // Arrange
        $requests = [
            $this->createDsarRequest('dsar-1', 'sub-1'),
            $this->createDsarRequest('dsar-2', 'sub-2'),
            $this->createDsarRequest('dsar-3', 'sub-3'),
        ];

        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findAll')->willReturn($requests);

        // Act
        $results = $stub->findAll();

        // Assert
        self::assertCount(3, $results);
        self::assertSame('dsar-1', $results[0]->id);
        self::assertSame('dsar-3', $results[2]->id);
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNoRequests(): void
    {
        // Arrange
        $stub = $this->createStub(DsarStoreInterface::class);
        $stub->method('findAll')->willReturn([]);

        // Act & Assert
        self::assertSame([], $stub->findAll());
    }

    #[Test]
    public function saveAcceptsRequestWithoutError(): void
    {
        // Arrange
        $request = $this->createDsarRequest('dsar-save', 'sub-save');

        $stub = $this->createStub(DsarStoreInterface::class);

        // Act -- save is void; verifying no exception
        $stub->save($request);

        // Assert
        self::assertSame('dsar-save', $request->id);
    }

    #[Test]
    public function implementationCanPersistAndRetrieve(): void
    {
        // Arrange
        $store = $this->createInMemoryStore();
        $request = $this->createDsarRequest('dsar-mem', 'sub-mem');

        // Act
        $store->save($request);

        // Assert
        $found = $store->findById('dsar-mem');
        self::assertNotNull($found);
        self::assertSame('dsar-mem', $found->id);
        self::assertSame('sub-mem', $found->subjectId);
    }

    #[Test]
    public function implementationFindBySubjectReturnsCorrectRequest(): void
    {
        // Arrange
        $store = $this->createInMemoryStore();
        $store->save($this->createDsarRequest('a', 'user-1'));
        $store->save($this->createDsarRequest('b', 'user-2'));

        // Act
        $result = $store->findBySubject('user-2');

        // Assert
        self::assertNotNull($result);
        self::assertSame('b', $result->id);
    }

    #[Test]
    public function implementationFindAllReturnsAllSaved(): void
    {
        // Arrange
        $store = $this->createInMemoryStore();
        $store->save($this->createDsarRequest('x', 'sub-x'));
        $store->save($this->createDsarRequest('y', 'sub-y'));

        // Act
        $all = $store->findAll();

        // Assert
        self::assertCount(2, $all);
    }

    #[Test]
    public function implementationFindByIdReturnsNullForMissing(): void
    {
        // Arrange
        $store = $this->createInMemoryStore();

        // Act & Assert
        self::assertNull($store->findById('missing'));
    }

    private function createDsarRequest(string $id, string $subjectId): DsarRequest
    {
        $now = new DateTimeImmutable();
        $deadline = $now->modify('+30 days');

        return new DsarRequest(
            id: $id,
            subjectId: $subjectId,
            email: $subjectId . '@example.com',
            status: DsarStatus::Pending,
            createdAt: $now,
            deadline: $deadline,
        );
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
                foreach ($this->requests as $request) {
                    if ($request->subjectId === $subjectId) {
                        return $request;
                    }
                }

                return null;
            }

            public function findAll(): array
            {
                return array_values($this->requests);
            }
        };
    }
}
