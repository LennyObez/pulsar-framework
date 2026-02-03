<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\PreflightCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;

#[CoversClass(PreflightRunner::class)]
final class PreflightRunnerTest extends TestCase
{
    #[Test]
    public function it_returns_empty_results_when_no_checks_registered(): void
    {
        $runner = new PreflightRunner([]);

        self::assertSame([], $runner->run());
    }

    #[Test]
    public function it_runs_all_registered_checks(): void
    {
        $check1 = $this->createStub(PreflightCheckInterface::class);
        $check1->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'Check 1 passed',
        ));

        $check2 = $this->createStub(PreflightCheckInterface::class);
        $check2->method('check')->willReturn(new PreflightCheckResult(
            passed: false,
            message: 'Check 2 failed',
        ));

        $runner = new PreflightRunner([$check1, $check2]);
        $results = $runner->run();

        self::assertCount(2, $results);
        self::assertTrue($results[0]->passed);
        self::assertFalse($results[1]->passed);
    }

    #[Test]
    public function it_preserves_check_order_in_results(): void
    {
        $checks = [];
        for ($i = 1; $i <= 5; $i++) {
            $check = $this->createStub(PreflightCheckInterface::class);
            $check->method('check')->willReturn(new PreflightCheckResult(
                passed: true,
                message: "Check {$i}",
            ));
            $checks[] = $check;
        }

        $runner = new PreflightRunner($checks);
        $results = $runner->run();

        self::assertCount(5, $results);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('Check ' . ($i + 1), $results[$i]->message);
        }
    }

    #[Test]
    public function all_passed_returns_true_when_all_checks_pass(): void
    {
        $check1 = $this->createStub(PreflightCheckInterface::class);
        $check1->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'OK',
        ));

        $check2 = $this->createStub(PreflightCheckInterface::class);
        $check2->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'OK',
        ));

        $runner = new PreflightRunner([$check1, $check2]);

        self::assertTrue($runner->allPassed());
    }

    #[Test]
    public function all_passed_returns_false_when_any_check_fails(): void
    {
        $passing = $this->createStub(PreflightCheckInterface::class);
        $passing->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'OK',
        ));

        $failing = $this->createStub(PreflightCheckInterface::class);
        $failing->method('check')->willReturn(new PreflightCheckResult(
            passed: false,
            message: 'Failed',
        ));

        $runner = new PreflightRunner([$passing, $failing]);

        self::assertFalse($runner->allPassed());
    }

    #[Test]
    public function all_passed_returns_true_when_no_checks_registered(): void
    {
        $runner = new PreflightRunner([]);

        self::assertTrue($runner->allPassed());
    }

    #[Test]
    public function all_passed_short_circuits_on_first_failure(): void
    {
        $failing = $this->createMock(PreflightCheckInterface::class);
        $failing->expects(self::once())->method('check')->willReturn(new PreflightCheckResult(
            passed: false,
            message: 'Failed',
        ));

        $neverReached = $this->createMock(PreflightCheckInterface::class);
        $neverReached->expects(self::never())->method('check');

        $runner = new PreflightRunner([$failing, $neverReached]);

        self::assertFalse($runner->allPassed());
    }

    #[Test]
    public function it_includes_findings_in_results(): void
    {
        $check = $this->createStub(PreflightCheckInterface::class);
        $check->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'Memory OK',
            findings: ['Current: 50 MB', 'Threshold: 128 MB'],
        ));

        $runner = new PreflightRunner([$check]);
        $results = $runner->run();

        self::assertCount(2, $results[0]->findings);
        self::assertSame('Current: 50 MB', $results[0]->findings[0]);
        self::assertSame('Threshold: 128 MB', $results[0]->findings[1]);
    }
}
