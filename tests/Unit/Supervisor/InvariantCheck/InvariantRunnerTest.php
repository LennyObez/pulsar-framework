<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\InvariantCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckInterface;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;
use Pulsar\Supervisor\InvariantCheck\InvariantRunner;

#[CoversClass(InvariantRunner::class)]
final class InvariantRunnerTest extends TestCase
{
    #[Test]
    public function it_returns_empty_results_when_no_checks_registered(): void
    {
        $runner = new InvariantRunner([]);

        self::assertSame([], $runner->run());
    }

    #[Test]
    public function it_runs_all_registered_checks(): void
    {
        $check1 = $this->createStub(InvariantCheckInterface::class);
        $check1->method('check')->willReturn(new InvariantCheckResult(
            passed: true,
            message: 'DB connected',
        ));

        $check2 = $this->createStub(InvariantCheckInterface::class);
        $check2->method('check')->willReturn(new InvariantCheckResult(
            passed: false,
            message: 'Redis unavailable',
        ));

        $runner = new InvariantRunner([$check1, $check2]);
        $results = $runner->run();

        self::assertCount(2, $results);
        self::assertTrue($results[0]->passed);
        self::assertSame('DB connected', $results[0]->message);
        self::assertFalse($results[1]->passed);
        self::assertSame('Redis unavailable', $results[1]->message);
    }

    #[Test]
    public function it_preserves_check_order_in_results(): void
    {
        $checks = [];
        for ($i = 1; $i <= 4; $i++) {
            $check = $this->createStub(InvariantCheckInterface::class);
            $check->method('check')->willReturn(new InvariantCheckResult(
                passed: true,
                message: "Invariant {$i}",
            ));
            $checks[] = $check;
        }

        $runner = new InvariantRunner($checks);
        $results = $runner->run();

        self::assertCount(4, $results);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame('Invariant ' . ($i + 1), $results[$i]->message);
        }
    }

    #[Test]
    public function all_passed_returns_true_when_all_checks_pass(): void
    {
        $check1 = $this->createStub(InvariantCheckInterface::class);
        $check1->method('check')->willReturn(new InvariantCheckResult(
            passed: true,
            message: 'OK',
        ));

        $check2 = $this->createStub(InvariantCheckInterface::class);
        $check2->method('check')->willReturn(new InvariantCheckResult(
            passed: true,
            message: 'OK',
        ));

        $runner = new InvariantRunner([$check1, $check2]);

        self::assertTrue($runner->allPassed());
    }

    #[Test]
    public function all_passed_returns_false_when_any_check_fails(): void
    {
        $passing = $this->createStub(InvariantCheckInterface::class);
        $passing->method('check')->willReturn(new InvariantCheckResult(
            passed: true,
            message: 'OK',
        ));

        $failing = $this->createStub(InvariantCheckInterface::class);
        $failing->method('check')->willReturn(new InvariantCheckResult(
            passed: false,
            message: 'Violation',
        ));

        $runner = new InvariantRunner([$passing, $failing]);

        self::assertFalse($runner->allPassed());
    }

    #[Test]
    public function all_passed_returns_true_when_no_checks_registered(): void
    {
        $runner = new InvariantRunner([]);

        self::assertTrue($runner->allPassed());
    }

    #[Test]
    public function all_passed_short_circuits_on_first_failure(): void
    {
        $failing = $this->createMock(InvariantCheckInterface::class);
        $failing->expects(self::once())->method('check')->willReturn(new InvariantCheckResult(
            passed: false,
            message: 'Failed',
        ));

        $neverReached = $this->createMock(InvariantCheckInterface::class);
        $neverReached->expects(self::never())->method('check');

        $runner = new InvariantRunner([$failing, $neverReached]);

        self::assertFalse($runner->allPassed());
    }

    #[Test]
    public function it_includes_findings_in_results(): void
    {
        $check = $this->createStub(InvariantCheckInterface::class);
        $check->method('check')->willReturn(new InvariantCheckResult(
            passed: false,
            message: 'Config drift detected',
            findings: ['Expected key: app.name', 'Actual: missing'],
        ));

        $runner = new InvariantRunner([$check]);
        $results = $runner->run();

        self::assertCount(2, $results[0]->findings);
        self::assertSame('Expected key: app.name', $results[0]->findings[0]);
        self::assertSame('Actual: missing', $results[0]->findings[1]);
    }
}
