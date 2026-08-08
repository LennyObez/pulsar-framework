<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_values;
use function is_dir;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

/**
 * Guards the gate that guards the suite.
 *
 * tools/ci/assert-test-yield.php exists to catch a run that reported success after
 * executing almost nothing. A gate of that kind is worth exactly as much as its own
 * correctness, and it is the one gate whose silence cannot be noticed: if it never
 * fails, that is indistinguishable from health.
 *
 * So each of its four outcomes is exercised here — including the two argument
 * errors, because a gate that exits 2 on a typo in its own invocation would be
 * skipped by a CI step that only checks for exit 1.
 *
 * The last case is the one that matters most. The script's docblock claims it
 * counts testcase elements rather than the summary attributes on <testsuite>,
 * because those attributes differ between PHPUnit's writer and a parallel runner's
 * merged output. That claim is asserted against a report whose attributes say one
 * thing and whose elements say another.
 */
final class TestYieldGateTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-test-yield.php';

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
    public function itPassesWhenTheRunIsWithinBounds(): void
    {
        $report = $this->report(total: 100, skipped: 1);

        [$status, $stdout] = $this->invokeGate($report, '--min-tests=90', '--max-skipped-percent=2.0');

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('within bounds', $stdout);
    }

    #[Test]
    public function itFailsWhenFewerTestsRanThanTheFloor(): void
    {
        $report = $this->report(total: 40, skipped: 0);

        [$status, , $stderr] = $this->invokeGate($report, '--min-tests=100', '--max-skipped-percent=100');

        self::assertSame(1, $status);
        self::assertStringContainsString('below the floor of 100', $stderr);
        self::assertStringContainsString('never collected', $stderr);
    }

    #[Test]
    public function itFailsWhenTooManyTestsAbstained(): void
    {
        // A tenth of the suite self-skipping is what an absent extension looks like.
        $report = $this->report(total: 100, skipped: 10);

        [$status, , $stderr] = $this->invokeGate($report, '--min-tests=50', '--max-skipped-percent=1.0');

        self::assertSame(1, $status);
        self::assertStringContainsString('10.000% of tests skipped', $stderr);
        self::assertStringContainsString('measured its own environment', $stderr);
    }

    /**
     * A missing report is the loudest possible collapse and must not read as success.
     *
     * If the test step's --log-junit path is wrong, or the runner died before writing,
     * there is no evidence at all — which is indistinguishable from having executed
     * nothing, and must be treated as such rather than shrugged off.
     */
    #[Test]
    public function itFailsWhenTheReportIsAbsentRatherThanAssumingSuccess(): void
    {
        [$status, , $stderr] = $this->invokeGate(
            sys_get_temp_dir() . '/pulsar-yield-absent-' . bin2hex(random_bytes(6)) . '.xml',
            '--min-tests=1',
            '--max-skipped-percent=1',
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('no JUnit report', $stderr);
    }

    #[Test]
    public function itRefusesToRunWithoutBothBounds(): void
    {
        $report = $this->report(total: 10, skipped: 0);

        // Exit 2, distinct from the exit 1 that means "the run was bad": a gate
        // invoked wrongly has measured nothing and must not be mistaken for one
        // that measured something acceptable.
        [$missingBoth] = $this->invokeGate($report);
        [$missingCeiling] = $this->invokeGate($report, '--min-tests=1');
        [$missingFloor] = $this->invokeGate($report, '--max-skipped-percent=1');

        self::assertSame(2, $missingBoth);
        self::assertSame(2, $missingCeiling);
        self::assertSame(2, $missingFloor);
    }

    /**
     * The counting claim in the script's docblock, held against a report that lies.
     */
    #[Test]
    public function itCountsElementsRatherThanTrustingTheSummaryAttributes(): void
    {
        $path = sys_get_temp_dir() . '/pulsar-yield-' . bin2hex(random_bytes(6)) . '.xml';
        $this->written[] = $path;

        // The attributes claim a clean run of three; the elements record five cases,
        // three of them skipped. A gate reading the attributes would pass this.
        file_put_contents($path, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="Unit" tests="3" skipped="0">
                <testcase name="a" class="A"/>
                <testcase name="b" class="A"><skipped/></testcase>
                <testcase name="c" class="A"><skipped/></testcase>
                <testcase name="d" class="A"><skipped/></testcase>
                <testcase name="e" class="A"/>
              </testsuite>
            </testsuites>
            XML);

        [$status, $stdout, $stderr] = $this->invokeGate($path, '--min-tests=5', '--max-skipped-percent=1.0');

        self::assertStringContainsString('Executed  : 5', $stdout);
        self::assertStringContainsString('Skipped   : 3', $stdout);
        self::assertSame(1, $status, 'the summary attributes were trusted over the elements');
        self::assertStringContainsString('60.000% of tests skipped', $stderr);
    }

    /**
     * Writes a JUnit report with the requested shape and returns its path.
     */
    private function report(int $total, int $skipped): string
    {
        $path = sys_get_temp_dir() . '/pulsar-yield-' . bin2hex(random_bytes(6)) . '.xml';
        $this->written[] = $path;

        $cases = '';

        for ($i = 0; $i < $total; ++$i) {
            $cases .= $i < $skipped
                ? sprintf('    <testcase name="t%d" class="C"><skipped/></testcase>%s', $i, "\n")
                : sprintf('    <testcase name="t%d" class="C"/>%s', $i, "\n");
        }

        file_put_contents($path, sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"S\">\n%s  </testsuite>\n</testsuites>\n",
            $cases,
        ));

        return $path;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invokeGate(string $report, string ...$bounds): array
    {
        // array_values, because proc_open wants a list and unpacking a variadic does not
        // guarantee one — a named argument would give it a string key.
        $command = array_values([PHP_BINARY, self::SCRIPT, $report, ...$bounds]);

        // Descriptor 0 is given its own pipe and closed at once, rather than left
        // unspecified. An unspecified descriptor is INHERITED, and under a parallel
        // test runner the inherited stdin is the pipe the runner uses to send its
        // worker the next command. Handing a duplicate of that to an unrelated
        // subprocess is a coordination hazard for the sake of nothing: this child
        // reads no input, so it should be given none.
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            is_dir(__DIR__) ? __DIR__ : null,
        );

        self::assertIsResource($process, 'could not start the gate');

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        return [$status, $stdout, $stderr];
    }
}
