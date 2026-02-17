<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Deploy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\DeployReport;
use Pulsar\Deploy\Exception\DeployException;
use RuntimeException;

#[CoversClass(DeployCheck::class)]
#[CoversClass(DeployReport::class)]
#[CoversClass(CheckResult::class)]
final class DeployIntegrationTest extends TestCase
{
    #[Test]
    public function it_aggregates_multiple_check_results_into_a_report(): void
    {
        $passingCheck = $this->createCheck('cache-check', 'Cache settings', CheckResult::pass(
            'cache-check',
            'OPcache is properly configured',
        ));

        $warningCheck = $this->createCheck('tls-check', 'TLS configuration', CheckResult::warning(
            'tls-check',
            'TLS 1.3 not enforced in staging',
            ['Upgrade to TLS 1.3 for production parity.'],
        ));

        $errorCheck = $this->createCheck('debug-check', 'Debug mode', CheckResult::error(
            'debug-check',
            'Debug mode is enabled in production',
            ['Set APP_DEBUG=false in environment.'],
        ));

        $runner = new DeployCheck([$passingCheck, $warningCheck, $errorCheck]);
        $report = $runner->run('production');

        self::assertSame(3, $report->total());
        self::assertSame(1, $report->passed);
        self::assertSame(1, $report->warnings);
        self::assertSame(1, $report->errors);
        self::assertSame('production', $report->environment);
        self::assertFalse($report->allPassed());
        self::assertFalse($report->hasNoErrors());
    }

    #[Test]
    public function it_reports_all_passed_when_no_warnings_or_errors(): void
    {
        $check1 = $this->createCheck('check-a', 'Check A', CheckResult::pass('check-a', 'OK'));
        $check2 = $this->createCheck('check-b', 'Check B', CheckResult::pass('check-b', 'OK'));

        $runner = new DeployCheck([$check1, $check2]);
        $report = $runner->run('staging');

        self::assertTrue($report->allPassed());
        self::assertTrue($report->hasNoErrors());
        self::assertSame(2, $report->passed);
        self::assertSame(0, $report->warnings);
        self::assertSame(0, $report->errors);
    }

    #[Test]
    public function it_has_no_errors_with_only_warnings(): void
    {
        $check = $this->createCheck('warn-check', 'Warning check', CheckResult::warning(
            'warn-check',
            'Non-critical issue found',
        ));

        $runner = new DeployCheck([$check]);
        $report = $runner->run('local');

        self::assertFalse($report->allPassed());
        self::assertTrue($report->hasNoErrors());
    }

    #[Test]
    public function it_catches_check_exceptions_and_converts_to_errors(): void
    {
        $failingCheck = new class implements DeployCheckInterface {
            public function getName(): string
            {
                return 'crashing-check';
            }

            public function getDescription(): string
            {
                return 'A check that throws';
            }

            public function check(string $environment): CheckResult
            {
                throw new RuntimeException('Database connection failed');
            }
        };

        $runner = new DeployCheck([$failingCheck]);
        $report = $runner->run('production');

        self::assertSame(1, $report->errors);
        self::assertSame(0, $report->passed);
        self::assertCount(1, $report->results);
        self::assertSame(CheckSeverity::Error, $report->results[0]->severity);
        self::assertStringContainsString('Database connection failed', $report->results[0]->message);
    }

    #[Test]
    public function it_rejects_invalid_environment_name(): void
    {
        $runner = new DeployCheck();

        $this->expectException(DeployException::class);

        $runner->run('invalid-env');
    }

    #[Test]
    public function it_supports_registering_checks_after_construction(): void
    {
        $runner = new DeployCheck();
        self::assertCount(0, $runner->checks());

        $check = $this->createCheck('late-check', 'Late check', CheckResult::pass('late-check', 'OK'));
        $runner->register($check);

        self::assertCount(1, $runner->checks());

        $report = $runner->run('local');
        self::assertSame(1, $report->total());
    }

    #[Test]
    public function it_runs_empty_check_list_without_error(): void
    {
        $runner = new DeployCheck();
        $report = $runner->run('production');

        self::assertSame(0, $report->total());
        self::assertTrue($report->allPassed());
    }

    #[Test]
    public function it_preserves_recommendations_in_results(): void
    {
        $recommendations = [
            'Enable OPcache in production.',
            'Set opcache.validate_timestamps=0 for better performance.',
        ];

        $check = $this->createCheck('rec-check', 'Rec check', CheckResult::warning(
            'rec-check',
            'Performance can be improved',
            $recommendations,
        ));

        $runner = new DeployCheck([$check]);
        $report = $runner->run('production');

        self::assertCount(2, $report->results[0]->recommendations);
        self::assertSame($recommendations, $report->results[0]->recommendations);
    }

    #[Test]
    public function it_runs_checks_against_all_valid_environments(): void
    {
        $environments = ['local', 'staging', 'production'];
        $check = $this->createCheck('env-check', 'Env check', CheckResult::pass('env-check', 'OK'));

        foreach ($environments as $env) {
            $runner = new DeployCheck([$check]);
            $report = $runner->run($env);

            self::assertSame($env, $report->environment);
            self::assertSame(1, $report->total());
        }
    }

    #[Test]
    public function check_severity_enum_identifies_passing_and_failure(): void
    {
        self::assertTrue(CheckSeverity::Pass->isPassing());
        self::assertFalse(CheckSeverity::Pass->isFailure());

        self::assertFalse(CheckSeverity::Warning->isPassing());
        self::assertTrue(CheckSeverity::Warning->isFailure());

        self::assertFalse(CheckSeverity::Error->isPassing());
        self::assertTrue(CheckSeverity::Error->isFailure());
    }

    private function createCheck(string $name, string $description, CheckResult $result): DeployCheckInterface
    {
        return new class ($name, $description, $result) implements DeployCheckInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly CheckResult $result,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function check(string $environment): CheckResult
            {
                return $this->result;
            }
        };
    }
}
