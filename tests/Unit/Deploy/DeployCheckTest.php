<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\Exception\DeployException;
use RuntimeException;

#[CoversClass(DeployCheck::class)]
final class DeployCheckTest extends TestCase
{
    #[Test]
    public function it_runs_all_registered_checks(): void
    {
        $check1 = $this->createPassingCheck('check-1', 'First is fine');
        $check2 = $this->createPassingCheck('check-2', 'Second is fine');

        $deployCheck = new DeployCheck([$check1, $check2]);
        $report = $deployCheck->run('production');

        self::assertCount(2, $report->results);
        self::assertSame(2, $report->passed);
        self::assertSame(0, $report->warnings);
        self::assertSame(0, $report->errors);
        self::assertSame('production', $report->environment);
    }

    #[Test]
    public function it_counts_pass_warning_and_error_severities(): void
    {
        $pass = $this->createCheckReturning(CheckResult::pass('p', 'ok'));
        $warn = $this->createCheckReturning(CheckResult::warning('w', 'warn'));
        $error = $this->createCheckReturning(CheckResult::error('e', 'err'));

        $deployCheck = new DeployCheck([$pass, $warn, $error]);
        $report = $deployCheck->run('production');

        self::assertSame(1, $report->passed);
        self::assertSame(1, $report->warnings);
        self::assertSame(1, $report->errors);
        self::assertSame(3, $report->total());
    }

    #[Test]
    public function it_registers_checks_dynamically(): void
    {
        $deployCheck = new DeployCheck();
        self::assertSame([], $deployCheck->checks());

        $check = $this->createPassingCheck('dynamic', 'registered');
        $deployCheck->register($check);

        self::assertCount(1, $deployCheck->checks());

        $report = $deployCheck->run('production');
        self::assertCount(1, $report->results);
        self::assertSame(1, $report->passed);
    }

    #[Test]
    public function it_returns_checks_list(): void
    {
        $check1 = $this->createPassingCheck('a', 'a');
        $check2 = $this->createPassingCheck('b', 'b');

        $deployCheck = new DeployCheck([$check1, $check2]);

        $checks = $deployCheck->checks();
        self::assertCount(2, $checks);
        self::assertSame($check1, $checks[0]);
        self::assertSame($check2, $checks[1]);
    }

    #[Test]
    public function it_catches_exceptions_from_checks_and_records_error(): void
    {
        $throwingCheck = $this->createStub(DeployCheckInterface::class);
        $throwingCheck->method('getName')->willReturn('broken');
        $throwingCheck->method('getDescription')->willReturn('A broken check');
        $throwingCheck->method('check')->willThrowException(new RuntimeException('Something exploded'));

        $deployCheck = new DeployCheck([$throwingCheck]);
        $report = $deployCheck->run('production');

        self::assertCount(1, $report->results);
        self::assertSame(CheckSeverity::Error, $report->results[0]->severity);
        self::assertStringContainsString('Something exploded', $report->results[0]->message);
        self::assertSame('broken', $report->results[0]->name);
        self::assertSame(0, $report->passed);
        self::assertSame(0, $report->warnings);
        self::assertSame(1, $report->errors);
    }

    #[Test]
    public function it_throws_for_invalid_environment(): void
    {
        $deployCheck = new DeployCheck();

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('Invalid deployment environment "banana"');

        $deployCheck->run('banana');
    }

    #[Test]
    public function it_accepts_local_environment(): void
    {
        $deployCheck = new DeployCheck([$this->createPassingCheck('c', 'ok')]);
        $report = $deployCheck->run('local');

        self::assertSame('local', $report->environment);
        self::assertSame(1, $report->passed);
    }

    #[Test]
    public function it_accepts_staging_environment(): void
    {
        $deployCheck = new DeployCheck([$this->createPassingCheck('c', 'ok')]);
        $report = $deployCheck->run('staging');

        self::assertSame('staging', $report->environment);
    }

    #[Test]
    public function it_accepts_production_environment(): void
    {
        $deployCheck = new DeployCheck([$this->createPassingCheck('c', 'ok')]);
        $report = $deployCheck->run('production');

        self::assertSame('production', $report->environment);
    }

    #[Test]
    public function it_returns_empty_report_with_no_checks(): void
    {
        $deployCheck = new DeployCheck();
        $report = $deployCheck->run('production');

        self::assertSame([], $report->results);
        self::assertSame(0, $report->passed);
        self::assertSame(0, $report->warnings);
        self::assertSame(0, $report->errors);
        self::assertTrue($report->allPassed());
    }

    #[Test]
    public function it_handles_mixed_pass_and_exception_checks(): void
    {
        $passing = $this->createPassingCheck('ok-check', 'Fine');

        $throwing = $this->createStub(DeployCheckInterface::class);
        $throwing->method('getName')->willReturn('fail-check');
        $throwing->method('check')->willThrowException(new RuntimeException('Boom'));

        $deployCheck = new DeployCheck([$passing, $throwing]);
        $report = $deployCheck->run('staging');

        self::assertSame(1, $report->passed);
        self::assertSame(1, $report->errors);
        self::assertSame(2, $report->total());
    }

    private function createPassingCheck(string $name, string $message): DeployCheckInterface
    {
        return $this->createCheckReturning(CheckResult::pass($name, $message));
    }

    private function createCheckReturning(CheckResult $result): DeployCheckInterface
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn($result->name);
        $check->method('getDescription')->willReturn('Mock check');
        $check->method('check')->willReturn($result);

        return $check;
    }
}
