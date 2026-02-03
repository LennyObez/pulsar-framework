<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use function count;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(LogLevel::class)]
final class LogLevelTest extends TestCase
{
    #[Test]
    public function hasAllEightPsr3Levels(): void
    {
        $cases = LogLevel::cases();

        self::assertCount(8, $cases);
    }

    #[Test]
    public function emergencyHasSeverityZero(): void
    {
        self::assertSame(0, LogLevel::Emergency->severity());
    }

    #[Test]
    public function alertHasSeverityOne(): void
    {
        self::assertSame(1, LogLevel::Alert->severity());
    }

    #[Test]
    public function criticalHasSeverityTwo(): void
    {
        self::assertSame(2, LogLevel::Critical->severity());
    }

    #[Test]
    public function errorHasSeverityThree(): void
    {
        self::assertSame(3, LogLevel::Error->severity());
    }

    #[Test]
    public function warningHasSeverityFour(): void
    {
        self::assertSame(4, LogLevel::Warning->severity());
    }

    #[Test]
    public function noticeHasSeverityFive(): void
    {
        self::assertSame(5, LogLevel::Notice->severity());
    }

    #[Test]
    public function infoHasSeveritySix(): void
    {
        self::assertSame(6, LogLevel::Info->severity());
    }

    #[Test]
    public function debugHasSeveritySeven(): void
    {
        self::assertSame(7, LogLevel::Debug->severity());
    }

    #[Test]
    public function severityDecreasesFromEmergencyToDebug(): void
    {
        $ordered = [
            LogLevel::Emergency,
            LogLevel::Alert,
            LogLevel::Critical,
            LogLevel::Error,
            LogLevel::Warning,
            LogLevel::Notice,
            LogLevel::Info,
            LogLevel::Debug,
        ];

        for ($i = 0; $i < count($ordered) - 1; $i++) {
            self::assertLessThan(
                $ordered[$i + 1]->severity(),
                $ordered[$i]->severity(),
                $ordered[$i]->name . ' should be more severe than ' . $ordered[$i + 1]->name,
            );
        }
    }

    #[Test]
    public function meetsThresholdWhenMoreSevere(): void
    {
        self::assertTrue(LogLevel::Emergency->meetsThreshold(LogLevel::Error));
        self::assertTrue(LogLevel::Critical->meetsThreshold(LogLevel::Warning));
    }

    #[Test]
    public function meetsThresholdWhenEqualSeverity(): void
    {
        self::assertTrue(LogLevel::Error->meetsThreshold(LogLevel::Error));
        self::assertTrue(LogLevel::Debug->meetsThreshold(LogLevel::Debug));
    }

    #[Test]
    public function doesNotMeetThresholdWhenLessSevere(): void
    {
        self::assertFalse(LogLevel::Debug->meetsThreshold(LogLevel::Error));
        self::assertFalse(LogLevel::Info->meetsThreshold(LogLevel::Warning));
        self::assertFalse(LogLevel::Warning->meetsThreshold(LogLevel::Critical));
    }

    #[Test]
    public function fromPsrLevelReturnsEnumInstanceAsIs(): void
    {
        $level = LogLevel::Error;

        self::assertSame($level, LogLevel::fromPsrLevel($level));
    }

    #[Test]
    public function fromPsrLevelParsesValidStrings(): void
    {
        self::assertSame(LogLevel::Emergency, LogLevel::fromPsrLevel('emergency'));
        self::assertSame(LogLevel::Alert, LogLevel::fromPsrLevel('alert'));
        self::assertSame(LogLevel::Critical, LogLevel::fromPsrLevel('critical'));
        self::assertSame(LogLevel::Error, LogLevel::fromPsrLevel('error'));
        self::assertSame(LogLevel::Warning, LogLevel::fromPsrLevel('warning'));
        self::assertSame(LogLevel::Notice, LogLevel::fromPsrLevel('notice'));
        self::assertSame(LogLevel::Info, LogLevel::fromPsrLevel('info'));
        self::assertSame(LogLevel::Debug, LogLevel::fromPsrLevel('debug'));
    }

    #[Test]
    public function fromPsrLevelDefaultsToDebugForUnknownString(): void
    {
        self::assertSame(LogLevel::Debug, LogLevel::fromPsrLevel('unknown'));
        self::assertSame(LogLevel::Debug, LogLevel::fromPsrLevel(''));
        self::assertSame(LogLevel::Debug, LogLevel::fromPsrLevel('EMERGENCY'));
    }

    #[Test]
    public function stringBackedValuesMatchPsr3Names(): void
    {
        self::assertSame('emergency', LogLevel::Emergency->value);
        self::assertSame('alert', LogLevel::Alert->value);
        self::assertSame('critical', LogLevel::Critical->value);
        self::assertSame('error', LogLevel::Error->value);
        self::assertSame('warning', LogLevel::Warning->value);
        self::assertSame('notice', LogLevel::Notice->value);
        self::assertSame('info', LogLevel::Info->value);
        self::assertSame('debug', LogLevel::Debug->value);
    }
}
