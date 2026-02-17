<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Internal\ExtensionBehaviorRecord;

#[CoversClass(ExtensionBehaviorRecord::class)]
final class ExtensionBehaviorRecordTest extends TestCase
{
    #[Test]
    public function defaultStateIsEmpty(): void
    {
        $record = new ExtensionBehaviorRecord();

        self::assertSame([], $record->capabilityUsage);
        self::assertSame([], $record->capabilityDenials);
        self::assertSame([], $record->errorTypes);
        self::assertSame(0, $record->errorCount);
        self::assertSame(0, $record->invocationCount);
        self::assertSame(0.0, $record->totalTimeMs);
        self::assertSame(0.0, $record->maxTimeMs);
        self::assertSame(0, $record->peakMemoryBytes);
        self::assertFalse($record->memoryWarningIssued);
    }

    #[Test]
    public function capabilityUsageCanBeIncremented(): void
    {
        $record = new ExtensionBehaviorRecord();

        $record->capabilityUsage['filesystem.read'] = 5;
        $record->capabilityUsage['network.http'] = 3;

        self::assertSame(5, $record->capabilityUsage['filesystem.read']);
        self::assertSame(3, $record->capabilityUsage['network.http']);
    }

    #[Test]
    public function errorTrackingAccumulatesCorrectly(): void
    {
        $record = new ExtensionBehaviorRecord();

        $record->errorCount = 3;
        $record->errorTypes['RuntimeException'] = 2;
        $record->errorTypes['InvalidArgumentException'] = 1;

        self::assertSame(3, $record->errorCount);
        self::assertCount(2, $record->errorTypes);
    }

    #[Test]
    public function timingDataTracksMaxAndTotal(): void
    {
        $record = new ExtensionBehaviorRecord();

        $record->invocationCount = 10;
        $record->totalTimeMs = 500.0;
        $record->maxTimeMs = 120.0;

        self::assertSame(10, $record->invocationCount);
        self::assertSame(500.0, $record->totalTimeMs);
        self::assertSame(120.0, $record->maxTimeMs);
    }

    #[Test]
    public function memoryTrackingRecordsPeakAndWarning(): void
    {
        $record = new ExtensionBehaviorRecord();

        $record->peakMemoryBytes = 52428800; // 50MB
        $record->memoryWarningIssued = true;

        self::assertSame(52428800, $record->peakMemoryBytes);
        self::assertTrue($record->memoryWarningIssued);
    }

    #[Test]
    public function capabilityDenialsTrackRejectedOperations(): void
    {
        $record = new ExtensionBehaviorRecord();

        $record->capabilityDenials['filesystem.write'] = 2;

        self::assertSame(2, $record->capabilityDenials['filesystem.write']);
    }
}
