<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarCollectorInterface;
use Pulsar\DataProtection\Dsar\DsarDataSet;

/**
 * Tests the DsarCollectorInterface contract via anonymous implementation and stubs.
 */
final class DsarCollectorInterfaceTest extends TestCase
{
    #[Test]
    public function implementationCollectsDataForSubject(): void
    {
        // Arrange
        $collector = $this->createFakeCollector(
            sourceName: 'UserProfiles',
            records: [['name' => 'Jane Doe', 'email' => 'jane@example.com']],
        );

        // Act
        $dataSet = $collector->collect('user-42');

        // Assert
        self::assertSame('UserProfiles', $dataSet->sourceName);
        self::assertSame('profile', $dataSet->category);
        self::assertCount(1, $dataSet->records);
        self::assertSame('Jane Doe', $dataSet->records[0]['name']);
    }

    #[Test]
    public function implementationReturnsSourceName(): void
    {
        // Arrange
        $collector = $this->createFakeCollector(sourceName: 'OrderHistory', records: []);

        // Act & Assert
        self::assertSame('OrderHistory', $collector->sourceName());
    }

    #[Test]
    public function implementationReturnsEmptyDataSetWhenNoDataExists(): void
    {
        // Arrange
        $collector = $this->createFakeCollector(sourceName: 'Analytics', records: []);

        // Act
        $dataSet = $collector->collect('unknown-subject');

        // Assert
        self::assertSame([], $dataSet->records);
        self::assertSame('Analytics', $dataSet->sourceName);
    }

    #[Test]
    public function implementationCanReturnMultipleRecords(): void
    {
        // Arrange
        $records = [
            ['order_id' => 1, 'total' => 99.99],
            ['order_id' => 2, 'total' => 49.50],
            ['order_id' => 3, 'total' => 15.00],
        ];
        $collector = $this->createFakeCollector(sourceName: 'Orders', records: $records);

        // Act
        $dataSet = $collector->collect('user-1');

        // Assert
        self::assertCount(3, $dataSet->records);
    }

    #[Test]
    public function stubSatisfiesInterfaceContract(): void
    {
        // Arrange
        $dataSet = new DsarDataSet('StubSource', 'general', [['key' => 'value']]);

        $stub = $this->createStub(DsarCollectorInterface::class);
        $stub->method('collect')->willReturn($dataSet);
        $stub->method('sourceName')->willReturn('StubSource');

        // Act
        $result = $stub->collect('any-subject');

        // Assert
        self::assertSame('StubSource', $stub->sourceName());
        self::assertSame('StubSource', $result->sourceName);
        self::assertCount(1, $result->records);
    }

    #[Test]
    public function emptyDataSetFactoryWorksWithCollector(): void
    {
        // Arrange
        $collector = new class implements DsarCollectorInterface {
            public function collect(string $subjectId): DsarDataSet
            {
                return DsarDataSet::empty($this->sourceName(), 'sessions');
            }

            public function sourceName(): string
            {
                return 'SessionStore';
            }
        };

        // Act
        $dataSet = $collector->collect('user-1');

        // Assert
        self::assertSame('SessionStore', $dataSet->sourceName);
        self::assertSame('sessions', $dataSet->category);
        self::assertSame([], $dataSet->records);
        self::assertSame([], $dataSet->attachments);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function createFakeCollector(string $sourceName, array $records): DsarCollectorInterface
    {
        return new class ($sourceName, $records) implements DsarCollectorInterface {
            /**
             * @param list<array<string, mixed>> $records
             */
            public function __construct(
                private readonly string $source,
                private readonly array $records,
            ) {}

            public function collect(string $subjectId): DsarDataSet
            {
                return new DsarDataSet($this->source, 'profile', $this->records);
            }

            public function sourceName(): string
            {
                return $this->source;
            }
        };
    }
}
