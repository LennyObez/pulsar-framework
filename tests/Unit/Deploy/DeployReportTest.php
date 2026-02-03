<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployReport;

#[CoversClass(DeployReport::class)]
final class DeployReportTest extends TestCase
{
    #[Test]
    public function it_exposes_all_constructor_properties(): void
    {
        $results = [
            CheckResult::pass('a', 'ok'),
            CheckResult::warning('b', 'warn'),
        ];

        $report = new DeployReport(
            results: $results,
            passed: 1,
            warnings: 1,
            errors: 0,
            environment: 'staging',
        );

        self::assertSame($results, $report->results);
        self::assertSame(1, $report->passed);
        self::assertSame(1, $report->warnings);
        self::assertSame(0, $report->errors);
        self::assertSame('staging', $report->environment);
    }

    #[Test]
    public function all_passed_returns_true_when_no_warnings_and_no_errors(): void
    {
        $report = new DeployReport(
            results: [CheckResult::pass('a', 'ok')],
            passed: 1,
            warnings: 0,
            errors: 0,
            environment: 'production',
        );

        self::assertTrue($report->allPassed());
    }

    #[Test]
    public function all_passed_returns_false_when_warnings_exist(): void
    {
        $report = new DeployReport(
            results: [CheckResult::warning('a', 'warn')],
            passed: 0,
            warnings: 1,
            errors: 0,
            environment: 'production',
        );

        self::assertFalse($report->allPassed());
    }

    #[Test]
    public function all_passed_returns_false_when_errors_exist(): void
    {
        $report = new DeployReport(
            results: [CheckResult::error('a', 'err')],
            passed: 0,
            warnings: 0,
            errors: 1,
            environment: 'production',
        );

        self::assertFalse($report->allPassed());
    }

    #[Test]
    public function has_no_errors_returns_true_when_zero_errors(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::pass('a', 'ok'),
                CheckResult::warning('b', 'warn'),
            ],
            passed: 1,
            warnings: 1,
            errors: 0,
            environment: 'staging',
        );

        self::assertTrue($report->hasNoErrors());
    }

    #[Test]
    public function has_no_errors_returns_false_when_errors_exist(): void
    {
        $report = new DeployReport(
            results: [CheckResult::error('a', 'err')],
            passed: 0,
            warnings: 0,
            errors: 1,
            environment: 'staging',
        );

        self::assertFalse($report->hasNoErrors());
    }

    #[Test]
    public function total_returns_sum_of_passed_warnings_and_errors(): void
    {
        $report = new DeployReport(
            results: [],
            passed: 3,
            warnings: 2,
            errors: 1,
            environment: 'production',
        );

        self::assertSame(6, $report->total());
    }

    #[Test]
    public function total_returns_zero_for_empty_report(): void
    {
        $report = new DeployReport(
            results: [],
            passed: 0,
            warnings: 0,
            errors: 0,
            environment: 'local',
        );

        self::assertSame(0, $report->total());
    }
}
