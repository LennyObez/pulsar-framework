<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\Internal\ExtensionBehaviorMonitor;
use Pulsar\Extensibility\Internal\ExtensionBehaviorRecord;

#[CoversClass(ExtensionBehaviorMonitor::class)]
#[CoversClass(ExtensionBehaviorRecord::class)]
final class ExtensionBehaviorMonitorTest extends TestCase
{
    private LoggerInterface $logger;
    private ExtensionBehaviorMonitor $monitor;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->monitor = new ExtensionBehaviorMonitor($this->logger);
    }

    #[Test]
    public function returnsNullForUnknownExtension(): void
    {
        self::assertNull($this->monitor->getRecord('nonexistent'));
    }

    #[Test]
    public function recordCapabilityUsageCreatesRecord(): void
    {
        $this->monitor->recordCapabilityUsage('ext-a', ExtensionCapability::ContainerRead);

        $record = $this->monitor->getRecord('ext-a');
        self::assertNotNull($record);
        self::assertSame(1, $record->capabilityUsage['ContainerRead']);
    }

    #[Test]
    public function recordCapabilityUsageIncrements(): void
    {
        $this->monitor->recordCapabilityUsage('ext-a', ExtensionCapability::ContainerRead);
        $this->monitor->recordCapabilityUsage('ext-a', ExtensionCapability::ContainerRead);
        $this->monitor->recordCapabilityUsage('ext-a', ExtensionCapability::FilesystemWrite);

        $record = $this->monitor->getRecord('ext-a');
        self::assertNotNull($record);
        self::assertSame(2, $record->capabilityUsage['ContainerRead']);
        self::assertSame(1, $record->capabilityUsage['FilesystemWrite']);
    }

    #[Test]
    public function recordCapabilityDenialTracksCount(): void
    {
        $this->monitor->recordCapabilityDenial('ext-b', ExtensionCapability::CryptoKeyAccess);

        $record = $this->monitor->getRecord('ext-b');
        self::assertNotNull($record);
        self::assertSame(1, $record->capabilityDenials['CryptoKeyAccess']);
    }

    #[Test]
    public function denialThresholdTriggersWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('capability denials'),
                self::callback(static fn(array $ctx): bool => $ctx['extension'] === 'ext-prober'),
            );

        $monitor = new ExtensionBehaviorMonitor($logger, capabilityDenialThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            $monitor->recordCapabilityDenial('ext-prober', ExtensionCapability::ProcessExec);
        }
    }

    #[Test]
    public function recordErrorIncrements(): void
    {
        $this->monitor->recordError('ext-c', 'RuntimeException');
        $this->monitor->recordError('ext-c', 'RuntimeException');
        $this->monitor->recordError('ext-c', 'LogicException');

        $record = $this->monitor->getRecord('ext-c');
        self::assertNotNull($record);
        self::assertSame(3, $record->errorCount);
        self::assertSame(2, $record->errorTypes['RuntimeException']);
        self::assertSame(1, $record->errorTypes['LogicException']);
    }

    #[Test]
    public function errorThresholdTriggersLogError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('possible malfunction'),
                self::callback(static fn(array $ctx): bool => $ctx['extension'] === 'ext-broken'),
            );

        $monitor = new ExtensionBehaviorMonitor($logger, errorRateThreshold: 2);

        $monitor->recordError('ext-broken', 'Error');
        $monitor->recordError('ext-broken', 'Error');
    }

    #[Test]
    public function timingRecordsDuration(): void
    {
        $startNs = $this->monitor->startTiming('ext-d');

        // Simulate some work
        $x = 0;
        for ($i = 0; $i < 1000; $i++) {
            $x += $i;
        }

        $this->monitor->stopTiming('ext-d', $startNs);

        $record = $this->monitor->getRecord('ext-d');
        self::assertNotNull($record);
        self::assertSame(1, $record->invocationCount);
        self::assertGreaterThan(0.0, $record->totalTimeMs);
        self::assertGreaterThan(0.0, $record->maxTimeMs);
    }

    #[Test]
    public function timingTracksMaxAcrossMultipleInvocations(): void
    {
        $start1 = $this->monitor->startTiming('ext-e');
        $this->monitor->stopTiming('ext-e', $start1);

        $start2 = $this->monitor->startTiming('ext-e');
        // Simulate slightly more work on second call
        $x = 0;
        for ($i = 0; $i < 10000; $i++) {
            $x += $i;
        }
        $this->monitor->stopTiming('ext-e', $start2);

        $record = $this->monitor->getRecord('ext-e');
        self::assertNotNull($record);
        self::assertSame(2, $record->invocationCount);
        self::assertGreaterThanOrEqual($record->maxTimeMs, $record->totalTimeMs);
    }

    #[Test]
    public function recordMemoryUsageTracksPeak(): void
    {
        $this->monitor->recordMemoryUsage('ext-f');

        $record = $this->monitor->getRecord('ext-f');
        self::assertNotNull($record);
        self::assertGreaterThan(0, $record->peakMemoryBytes);
    }

    #[Test]
    public function memoryThresholdTriggersWarningOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // Should warn exactly once even when called multiple times
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('memory usage'),
                self::callback(static fn(array $ctx): bool => $ctx['extension'] === 'ext-mem'),
            );

        // Set threshold very low so current process memory exceeds it
        $monitor = new ExtensionBehaviorMonitor($logger, memoryThresholdBytes: 1);

        $monitor->recordMemoryUsage('ext-mem');
        $monitor->recordMemoryUsage('ext-mem');

        $record = $monitor->getRecord('ext-mem');
        self::assertNotNull($record);
        self::assertTrue($record->memoryWarningIssued);
    }

    #[Test]
    public function allRecordsReturnsAllTrackedExtensions(): void
    {
        $this->monitor->recordCapabilityUsage('ext-1', ExtensionCapability::RouteRegister);
        $this->monitor->recordError('ext-2', 'Error');
        $this->monitor->recordCapabilityDenial('ext-3', ExtensionCapability::DatabaseRaw);

        $records = $this->monitor->allRecords();
        self::assertCount(3, $records);
        self::assertArrayHasKey('ext-1', $records);
        self::assertArrayHasKey('ext-2', $records);
        self::assertArrayHasKey('ext-3', $records);
    }

    #[Test]
    public function summaryReturnsStructuredData(): void
    {
        $this->monitor->recordCapabilityUsage('ext-good', ExtensionCapability::ContainerRead);
        $start = $this->monitor->startTiming('ext-good');
        $this->monitor->stopTiming('ext-good', $start);

        $this->monitor->recordError('ext-bad', 'RuntimeException');
        $this->monitor->recordCapabilityDenial('ext-bad', ExtensionCapability::ProcessExec);

        $summary = $this->monitor->summary();
        self::assertCount(2, $summary);

        $byName = [];
        foreach ($summary as $entry) {
            $byName[$entry['extension']] = $entry;
        }

        self::assertArrayHasKey('ext-good', $byName);
        self::assertSame(1, $byName['ext-good']['invocations']);
        self::assertSame(0, $byName['ext-good']['errors']);
        self::assertSame(0, $byName['ext-good']['denials']);
        self::assertGreaterThan(0.0, $byName['ext-good']['trust_score']);

        self::assertArrayHasKey('ext-bad', $byName);
        self::assertSame(1, $byName['ext-bad']['errors']);
        self::assertSame(1, $byName['ext-bad']['denials']);
    }

    #[Test]
    public function trustScoreDegradesWithErrors(): void
    {
        // Extension with no issues
        $this->monitor->recordCapabilityUsage('clean', ExtensionCapability::ContainerRead);
        $start = $this->monitor->startTiming('clean');
        $this->monitor->stopTiming('clean', $start);

        // Extension with many errors
        $monitor2 = new ExtensionBehaviorMonitor($this->logger, errorRateThreshold: 5);
        $monitor2->recordCapabilityUsage('buggy', ExtensionCapability::ContainerRead);
        $start = $monitor2->startTiming('buggy');
        $monitor2->stopTiming('buggy', $start);
        for ($i = 0; $i < 5; $i++) {
            $monitor2->recordError('buggy', 'Error');
        }

        $cleanSummary = $this->monitor->summary();
        $buggySummary = $monitor2->summary();

        self::assertGreaterThan($buggySummary[0]['trust_score'], $cleanSummary[0]['trust_score']);
    }

    #[Test]
    public function trustScoreDegradesWithDenials(): void
    {
        $this->monitor->recordCapabilityUsage('probing', ExtensionCapability::ContainerRead);
        // 5 denials, 1 usage = high denial ratio
        for ($i = 0; $i < 5; $i++) {
            $this->monitor->recordCapabilityDenial('probing', ExtensionCapability::ProcessExec);
        }

        $summary = $this->monitor->summary();
        // Denial rate = 5/(5+1) = 0.833, penalty = 0.833 * 0.3 = 0.25
        self::assertLessThan(1.0, $summary[0]['trust_score']);
    }

    #[Test]
    public function isFlaggedReturnsFalseForUnknownExtension(): void
    {
        self::assertFalse($this->monitor->isFlagged('unknown'));
    }

    #[Test]
    public function isFlaggedReturnsFalseForHealthyExtension(): void
    {
        $this->monitor->recordCapabilityUsage('healthy', ExtensionCapability::ContainerRead);
        self::assertFalse($this->monitor->isFlagged('healthy'));
    }

    #[Test]
    public function isFlaggedReturnsTrueWhenDenialThresholdExceeded(): void
    {
        $monitor = new ExtensionBehaviorMonitor($this->logger, capabilityDenialThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            $monitor->recordCapabilityDenial('probing', ExtensionCapability::ProcessExec);
        }

        self::assertTrue($monitor->isFlagged('probing'));
    }

    #[Test]
    public function isFlaggedReturnsTrueWhenTrustScoreLow(): void
    {
        $monitor = new ExtensionBehaviorMonitor($this->logger, errorRateThreshold: 2);

        // 1 invocation with many errors = very low trust
        $start = $monitor->startTiming('bad');
        $monitor->stopTiming('bad', $start);

        for ($i = 0; $i < 10; $i++) {
            $monitor->recordError('bad', 'CriticalError');
        }

        // Also add many denials to push score below 0.5
        for ($i = 0; $i < 5; $i++) {
            $monitor->recordCapabilityDenial('bad', ExtensionCapability::CryptoKeyAccess);
        }

        self::assertTrue($monitor->isFlagged('bad'));
    }

    #[Test]
    public function resetClearsAllRecords(): void
    {
        $this->monitor->recordCapabilityUsage('ext-x', ExtensionCapability::ContainerRead);
        $this->monitor->recordError('ext-y', 'Error');

        self::assertCount(2, $this->monitor->allRecords());

        $this->monitor->reset();

        self::assertSame([], $this->monitor->allRecords());
        self::assertNull($this->monitor->getRecord('ext-x'));
        self::assertNull($this->monitor->getRecord('ext-y'));
    }

    #[Test]
    public function separateExtensionsHaveIndependentRecords(): void
    {
        $this->monitor->recordError('ext-a', 'Error');
        $this->monitor->recordCapabilityUsage('ext-b', ExtensionCapability::RouteRegister);

        $recordA = $this->monitor->getRecord('ext-a');
        $recordB = $this->monitor->getRecord('ext-b');

        self::assertNotNull($recordA);
        self::assertNotNull($recordB);
        self::assertSame(1, $recordA->errorCount);
        self::assertSame(0, $recordB->errorCount);
        self::assertSame([], $recordA->capabilityUsage);
        self::assertSame(1, $recordB->capabilityUsage['RouteRegister']);
    }

    #[Test]
    public function trustScoreClampsBetweenZeroAndOne(): void
    {
        // Extreme case: tons of errors, tons of denials
        $monitor = new ExtensionBehaviorMonitor($this->logger, errorRateThreshold: 1);

        $start = $monitor->startTiming('terrible');
        $monitor->stopTiming('terrible', $start);

        for ($i = 0; $i < 100; $i++) {
            $monitor->recordError('terrible', 'FatalError');
            $monitor->recordCapabilityDenial('terrible', ExtensionCapability::ProcessExec);
        }

        $summary = $monitor->summary();
        self::assertGreaterThanOrEqual(0.0, $summary[0]['trust_score']);
        self::assertLessThanOrEqual(1.0, $summary[0]['trust_score']);
    }

    /**
     * @return iterable<string, array{ExtensionCapability}>
     */
    public static function capabilityProvider(): iterable
    {
        foreach (ExtensionCapability::cases() as $cap) {
            yield $cap->name => [$cap];
        }
    }

    #[Test]
    #[DataProvider('capabilityProvider')]
    public function allCapabilitiesCanBeRecorded(ExtensionCapability $capability): void
    {
        $this->monitor->recordCapabilityUsage('ext', $capability);
        $this->monitor->recordCapabilityDenial('ext', $capability);

        $record = $this->monitor->getRecord('ext');
        self::assertNotNull($record);
        self::assertSame(1, $record->capabilityUsage[$capability->name]);
        self::assertSame(1, $record->capabilityDenials[$capability->name]);
    }

    #[Test]
    public function belowThresholdDenialDoesNotWarn(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $monitor = new ExtensionBehaviorMonitor($logger, capabilityDenialThreshold: 5);

        for ($i = 0; $i < 4; $i++) {
            $monitor->recordCapabilityDenial('ext', ExtensionCapability::ProcessExec);
        }
    }

    #[Test]
    public function belowThresholdErrorDoesNotLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $monitor = new ExtensionBehaviorMonitor($logger, errorRateThreshold: 5);

        for ($i = 0; $i < 4; $i++) {
            $monitor->recordError('ext', 'Error');
        }
    }

    #[Test]
    public function noMemoryWarningWhenBelowThreshold(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        // Set threshold very high so current process won't exceed it
        $monitor = new ExtensionBehaviorMonitor($logger, memoryThresholdBytes: PHP_INT_MAX);

        $monitor->recordMemoryUsage('ext');

        $record = $monitor->getRecord('ext');
        self::assertNotNull($record);
        self::assertFalse($record->memoryWarningIssued);
    }
}
