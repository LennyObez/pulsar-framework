<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\PlaybookOutcome;
use Pulsar\Security\Incident\PlaybookResult;
use Pulsar\Security\ThreatDetection\ThreatCategory;

#[CoversClass(PlaybookResult::class)]
final class PlaybookResultTest extends TestCase
{
    public function testCompletedResult(): void
    {
        $result = PlaybookResult::completed(
            ThreatCategory::BruteForce,
            ['step1', 'step2'],
            halted: false,
        );

        self::assertSame(ThreatCategory::BruteForce, $result->category);
        self::assertSame(PlaybookOutcome::Completed, $result->outcome);
        self::assertSame(['step1', 'step2'], $result->executedSteps);
        self::assertSame('', $result->failedStep);
        self::assertSame('', $result->errorMessage);
        self::assertTrue($result->succeeded());
    }

    public function testHaltedResult(): void
    {
        $result = PlaybookResult::completed(
            ThreatCategory::InjectionAttempt,
            ['step1'],
            halted: true,
        );

        self::assertSame(PlaybookOutcome::Halted, $result->outcome);
        self::assertTrue($result->succeeded());
    }

    public function testNoPlaybookResult(): void
    {
        $result = PlaybookResult::noPlaybook(ThreatCategory::GeoAnomaly);

        self::assertSame(ThreatCategory::GeoAnomaly, $result->category);
        self::assertSame(PlaybookOutcome::NoPlaybook, $result->outcome);
        self::assertSame([], $result->executedSteps);
        self::assertFalse($result->succeeded());
    }

    public function testErrorResult(): void
    {
        $result = PlaybookResult::error(
            ThreatCategory::CredentialStuffing,
            ['step1', 'step2'],
            'step2',
            'Connection timeout',
        );

        self::assertSame(ThreatCategory::CredentialStuffing, $result->category);
        self::assertSame(PlaybookOutcome::Error, $result->outcome);
        self::assertSame(['step1', 'step2'], $result->executedSteps);
        self::assertSame('step2', $result->failedStep);
        self::assertSame('Connection timeout', $result->errorMessage);
        self::assertFalse($result->succeeded());
    }

    #[DataProvider('succeededProvider')]
    public function testSucceeded(PlaybookOutcome $outcome, bool $expected): void
    {
        $result = match ($outcome) {
            PlaybookOutcome::Completed => PlaybookResult::completed(ThreatCategory::BruteForce, [], false),
            PlaybookOutcome::Halted => PlaybookResult::completed(ThreatCategory::BruteForce, [], true),
            PlaybookOutcome::NoPlaybook => PlaybookResult::noPlaybook(ThreatCategory::BruteForce),
            PlaybookOutcome::Error => PlaybookResult::error(ThreatCategory::BruteForce, [], 'step', 'err'),
        };

        self::assertSame($expected, $result->succeeded());
    }

    /**
     * @return iterable<string, array{PlaybookOutcome, bool}>
     */
    public static function succeededProvider(): iterable
    {
        yield 'completed' => [PlaybookOutcome::Completed, true];
        yield 'halted' => [PlaybookOutcome::Halted, true];
        yield 'no playbook' => [PlaybookOutcome::NoPlaybook, false];
        yield 'error' => [PlaybookOutcome::Error, false];
    }
}
