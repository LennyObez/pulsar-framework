<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function file_put_contents;
use function is_file;
use function rand;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the gate that decides whether coverage is good enough.
 *
 * It had no test until now, which for this particular script is the worst place
 * to have none: it is the gate whose silence cannot be noticed. A floor that
 * never fails is indistinguishable from a codebase that always meets it.
 *
 * Two behaviours here are load-bearing and easy to get subtly wrong.
 *
 * A dimension with nothing to divide by was not measured, and printing "0.00%"
 * for it would read as a collapse in quality rather than as an absent driver —
 * PCOV has no notion of branches, so Conditions arrives as 0/0 under it.
 *
 * A dimension named on --report-only is printed and not gated. That option is for
 * a figure being measured for the first time, and a mistake in it would silently
 * stop enforcing a dimension everyone believes is enforced.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/assert-coverage-threshold.php', plants: 'a clover report with a dimension below the floor, and a dimension that was never measured being reported as a pass')]
final class CoverageThresholdGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-coverage-threshold.php';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
    }

    #[Test]
    public function itPassesWhenEveryDimensionMeetsTheFloor(): void
    {
        $report = $this->report(statements: [90, 100], conditionals: [85, 100], methods: [95, 100]);

        [$status, $stdout] = $this->runScript(self::SCRIPT, $report, '80');

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('90.00%', $stdout);
        self::assertStringContainsString('meets the 80.00% floor', $stdout);
    }

    #[Test]
    public function itFailsWhenADimensionIsBelowTheFloor(): void
    {
        $report = $this->report(statements: [70, 100], conditionals: [85, 100], methods: [95, 100]);

        [$status, , $stderr] = $this->runScript(self::SCRIPT, $report, '80');

        self::assertSame(1, $status);
        self::assertStringContainsString('Statements coverage 70.00% is below the 80.00% floor', $stderr);
    }

    #[Test]
    public function anUnmeasuredDimensionIsSaidToBeUnmeasuredRatherThanZero(): void
    {
        // What PCOV produces: lines and methods, no branches at all.
        $report = $this->report(statements: [90, 100], conditionals: [0, 0], methods: [95, 100]);

        [$status, $stdout] = $this->runScript(self::SCRIPT, $report, '80');

        self::assertSame(0, $status, 'an absent driver is not a coverage regression');
        self::assertStringContainsString('Conditions : not measured', $stdout);
        self::assertStringNotContainsString('Conditions :   0.00%', $stdout);
    }

    #[Test]
    public function aReportOnlyDimensionIsPrintedAndNotGated(): void
    {
        $report = $this->report(statements: [90, 100], conditionals: [12, 100], methods: [95, 100]);

        [$status, $stdout] = $this->runScript(self::SCRIPT, $report, '80', '--report-only=Conditions');

        self::assertSame(0, $status, 'a reported dimension must not decide the outcome');
        self::assertStringContainsString('12.00%', $stdout);
        self::assertStringContainsString('reported, not gated', $stdout);
    }

    #[Test]
    public function reportOnlyLeavesTheOtherDimensionsGated(): void
    {
        // The failure this guards: naming one dimension must not disarm the rest.
        $report = $this->report(statements: [40, 100], conditionals: [12, 100], methods: [95, 100]);

        [$status, , $stderr] = $this->runScript(self::SCRIPT, $report, '80', '--report-only=Conditions');

        self::assertSame(1, $status);
        self::assertStringContainsString('Statements coverage 40.00%', $stderr);
        self::assertStringNotContainsString('Conditions', $stderr);
    }

    #[Test]
    public function reportOnlyAcceptsSeveralDimensions(): void
    {
        $report = $this->report(statements: [40, 100], conditionals: [12, 100], methods: [30, 100]);

        [$status, $stdout] = $this->runScript(
            self::SCRIPT,
            $report,
            '80',
            '--report-only=Conditions,Methods',
        );

        self::assertSame(1, $status, 'Statements is still gated');
        self::assertStringContainsString('Methods    :  30.00% (30/100) — reported, not gated', $stdout);
    }

    #[Test]
    public function theOptionOrderDoesNotMatter(): void
    {
        $report = $this->report(statements: [90, 100], conditionals: [12, 100], methods: [95, 100]);

        [$status, $stdout] = $this->runScript(self::SCRIPT, '--report-only=Conditions', $report, '80');

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('reported, not gated', $stdout);
    }

    #[Test]
    public function aMissingReportIsRefused(): void
    {
        $absent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_absent_clover.xml';

        [$status, , $stderr] = $this->runScript(self::SCRIPT, $absent, '80');

        self::assertSame(1, $status);
        self::assertStringContainsString('not found', $stderr);
    }

    #[Test]
    public function anUnreadableReportIsRefused(): void
    {
        $garbage = $this->write('this is not xml');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, $garbage, '80');

        self::assertSame(1, $status);
        self::assertStringContainsString('not a readable Clover report', $stderr);
    }

    /**
     * @param array{0: int, 1: int} $statements
     * @param array{0: int, 1: int} $conditionals
     * @param array{0: int, 1: int} $methods
     */
    private function report(array $statements, array $conditionals, array $methods): string
    {
        return $this->write(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<coverage generated="1"><project timestamp="1"><metrics '
            . 'coveredstatements="' . $statements[0] . '" statements="' . $statements[1] . '" '
            . 'coveredconditionals="' . $conditionals[0] . '" conditionals="' . $conditionals[1] . '" '
            . 'coveredmethods="' . $methods[0] . '" methods="' . $methods[1] . '"'
            . '/></project></coverage>',
        );
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_clover_' . rand(100000, 999999) . '.xml';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
