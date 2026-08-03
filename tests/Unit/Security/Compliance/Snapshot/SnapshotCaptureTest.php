<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Snapshot;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Exception\ComplianceException;
use Pulsar\Security\Compliance\Snapshot\BeforeAfterSnapshot;
use Pulsar\Security\Compliance\Snapshot\ClassifiedField;
use Pulsar\Security\Compliance\Snapshot\SnapshotCapture;

#[CoversClass(SnapshotCapture::class)]
final class SnapshotCaptureTest extends TestCase
{
    #[Test]
    public function captureRefusesWithEmptyFields(): void
    {
        $this->expectException(ComplianceException::class);
        $this->expectExceptionMessageIsOrContains('Snapshot capture refused: no fields provided');

        (void) SnapshotCapture::capture('User', 'u-1');
    }

    #[Test]
    public function captureMasksRestrictedFields(): void
    {
        $snapshot = SnapshotCapture::capture(
            'User',
            'u-1',
            new ClassifiedField('ssn', '123-45-6789', DataClassification::Restricted),
        );

        self::assertCount(1, $snapshot->fields);
        self::assertSame('ssn', $snapshot->fields[0]->name);
        self::assertSame('[REDACTED]', $snapshot->fields[0]->value);
        self::assertSame(DataClassification::Restricted, $snapshot->fields[0]->classification);
    }

    #[Test]
    public function captureSkipsPublicFields(): void
    {
        $snapshot = SnapshotCapture::capture(
            'User',
            'u-1',
            new ClassifiedField('display_name', 'John', DataClassification::Public),
            new ClassifiedField('email', 'john@example.com', DataClassification::Internal),
        );

        self::assertCount(1, $snapshot->fields);
        self::assertSame('email', $snapshot->fields[0]->name);
        self::assertSame('john@example.com', $snapshot->fields[0]->value);
    }

    #[Test]
    public function captureKeepsInternalFieldsAsIs(): void
    {
        $snapshot = SnapshotCapture::capture(
            'Account',
            'acc-1',
            new ClassifiedField('role', 'admin', DataClassification::Internal),
        );

        self::assertCount(1, $snapshot->fields);
        self::assertSame('role', $snapshot->fields[0]->name);
        self::assertSame('admin', $snapshot->fields[0]->value);
        self::assertSame(DataClassification::Internal, $snapshot->fields[0]->classification);
    }

    #[Test]
    public function captureKeepsConfidentialFieldsAsIs(): void
    {
        $snapshot = SnapshotCapture::capture(
            'Account',
            'acc-1',
            new ClassifiedField('balance', 50000, DataClassification::Confidential),
        );

        self::assertCount(1, $snapshot->fields);
        self::assertSame('balance', $snapshot->fields[0]->name);
        self::assertSame(50000, $snapshot->fields[0]->value);
        self::assertSame(DataClassification::Confidential, $snapshot->fields[0]->classification);
    }

    #[Test]
    public function captureWithAllClassificationLevelsMixed(): void
    {
        $snapshot = SnapshotCapture::capture(
            'Employee',
            'emp-42',
            new ClassifiedField('display_name', 'Jane Smith', DataClassification::Public),
            new ClassifiedField('department', 'Engineering', DataClassification::Internal),
            new ClassifiedField('salary', 120000, DataClassification::Confidential),
            new ClassifiedField('ssn', '987-65-4321', DataClassification::Restricted),
        );

        // Public field excluded, 3 remaining
        self::assertCount(3, $snapshot->fields);

        // Internal — kept as-is
        self::assertSame('department', $snapshot->fields[0]->name);
        self::assertSame('Engineering', $snapshot->fields[0]->value);
        self::assertSame(DataClassification::Internal, $snapshot->fields[0]->classification);

        // Confidential — kept as-is
        self::assertSame('salary', $snapshot->fields[1]->name);
        self::assertSame(120000, $snapshot->fields[1]->value);
        self::assertSame(DataClassification::Confidential, $snapshot->fields[1]->classification);

        // Restricted — redacted
        self::assertSame('ssn', $snapshot->fields[2]->name);
        self::assertSame('[REDACTED]', $snapshot->fields[2]->value);
        self::assertSame(DataClassification::Restricted, $snapshot->fields[2]->classification);
    }

    #[Test]
    public function captureSetsEntityMetadata(): void
    {
        $snapshot = SnapshotCapture::capture(
            'Transaction',
            'txn-100',
            new ClassifiedField('amount', 500, DataClassification::Confidential),
        );

        self::assertSame('Transaction', $snapshot->entityType);
        self::assertSame('txn-100', $snapshot->entityId);
        self::assertInstanceOf(DateTimeImmutable::class, $snapshot->capturedAt);
    }

    #[Test]
    public function diffCreatesBeforeAfterSnapshot(): void
    {
        $before = SnapshotCapture::capture(
            'Account',
            'acc-1',
            new ClassifiedField('status', 'active', DataClassification::Internal),
        );

        $after = SnapshotCapture::capture(
            'Account',
            'acc-1',
            new ClassifiedField('status', 'suspended', DataClassification::Internal),
        );

        $diff = SnapshotCapture::diff($before, $after);

        self::assertInstanceOf(BeforeAfterSnapshot::class, $diff);
        self::assertSame($before, $diff->before);
        self::assertSame($after, $diff->after);
    }

    #[Test]
    public function captureWithOnlyPublicFieldsReturnsEmptyFieldList(): void
    {
        $snapshot = SnapshotCapture::capture(
            'Page',
            'page-1',
            new ClassifiedField('title', 'Homepage', DataClassification::Public),
            new ClassifiedField('slug', '/home', DataClassification::Public),
        );

        self::assertCount(0, $snapshot->fields);
        self::assertSame('Page', $snapshot->entityType);
        self::assertSame('page-1', $snapshot->entityId);
    }
}
