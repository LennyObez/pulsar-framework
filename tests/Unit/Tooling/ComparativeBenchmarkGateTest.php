<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Guards the comparative benchmark gate that blocks a slow pull request.
 *
 * benchmark-regression.yml runs benchmarks/Comparative/RunBenchmarks.php on every
 * pull request touching src/, extensions/ or benchmarks/, and refuses anything
 * more than 5% slower. Nothing had ever handed it a regression to refuse, so the
 * outcomes that matter are planted here: a regression, a baseline that cannot be
 * trusted, a baseline that matches nothing, a baseline that is not there, and an
 * honest run.
 *
 * The baselines are DERIVED from a real run rather than typed, then scaled. A
 * typed baseline would name benchmarks by hand, and the day a benchmark is
 * renamed the comparison silently becomes "NEW" for every subject — a gate
 * comparing nothing while printing a table that looks like a comparison. Deriving
 * the names means this test breaks loudly instead.
 *
 * The sample is 20 iterations rather than the 5000 CI uses. That is what
 * `--iterations` was added for: at the CI sample one run takes about two and a
 * half minutes, and a gate nobody can afford to exercise is a gate nobody
 * exercises. What is under test is the comparison and its exit code, and neither
 * depends on the sample size.
 *
 * WHAT THIS PARAGRAPH USED TO SAY, and why it no longer does. It read: "worth
 * knowing, and outside what a test can repair: tools/php/benchmark-baseline.json
 * as committed contains only its `_comment` key. Every benchmark therefore reports
 * NEW/OK on a pull request and the gate exits 0 having compared nothing. The
 * comparison below is real; the data the workflow feeds it is not yet."
 *
 * It was accurate and it was a note, which is the worst place for a finding of that
 * size to live: the check named "Performance Regression Check" was green on every
 * pull request of the release-candidate phase, and the only trace was a docblock.
 * Two things changed. RunBenchmarks.php now exits 1 when NO benchmark matched its
 * baseline — see {@see itRefusesABaselineThatMatchesNothingRatherThanCallingItAllNew},
 * which plants exactly the file that used to be committed — and the workflow
 * records its own baseline from the merge base, on the runner doing the comparing,
 * because an ops-per-second figure committed from one machine says nothing on
 * another. The committed placeholder is gone and .gitignore refuses its return.
 */
#[CoversNothing]
#[GuardsGate(gate: 'benchmarks/Comparative/RunBenchmarks.php', plants: 'a benchmark regressing past the 5% threshold, a malformed baseline entry that must be refused rather than compared against null, a baseline that matches no benchmark so the whole table reads NEW, and a baseline path that does not exist')]
final class ComparativeBenchmarkGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../benchmarks/Comparative/RunBenchmarks.php';

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

    /**
     * The planted defect: today's code, measured against a baseline it cannot meet.
     *
     * Scaling the recorded baseline up by ten is a 90% regression in the gate's
     * arithmetic — the shape of a change that adds a database round trip to route
     * matching, and eighteen times the 5% threshold.
     */
    #[Test]
    public function itFailsWhenABenchmarkRegressesPastTheThreshold(): void
    {
        $baseline = $this->baselineScaledBy(10.0);

        [$status, $stdout] = $this->runGate('--baseline=' . $baseline, '--threshold=5');

        self::assertSame(
            1,
            $status,
            'RunBenchmarks.php reported success against a baseline every benchmark missed by 90%. '
            . 'What ships on that silence: a change that makes routing, rendering or JSON encoding '
            . 'an order of magnitude slower merging with a green performance check, in a framework '
            . 'whose stated reason to exist is measurable performance leadership.',
        );
        self::assertStringContainsString('REGRESSIONS DETECTED', $stdout);
        self::assertStringContainsString('slower', $stdout);
    }

    /**
     * A baseline that cannot be read is not a baseline that was met.
     *
     * The script's own docblock says an entry with no numeric ops_per_sec used to
     * flow into the arithmetic, where a comparison against null passes and a real
     * regression ships. This holds it to that.
     */
    #[Test]
    public function itFailsOnABaselineItCannotTrustRatherThanComparingAgainstNothing(): void
    {
        $baseline = sys_get_temp_dir() . '/pulsar-baseline-' . bin2hex(random_bytes(6)) . '.json';
        $this->written[] = $baseline;
        file_put_contents($baseline, '{"hello_world": {"operations": 1234}}');

        [$status, $stdout, $stderr] = $this->runGate('--baseline=' . $baseline, '--threshold=5');

        self::assertNotSame(
            0,
            $status,
            'RunBenchmarks.php accepted a baseline with no measurement in it. What ships on that '
            . 'silence: a corrupt or half-written baseline read as "nothing to compare", and every '
            . 'regression after it waved through by a file that says nothing at all.',
        );
        self::assertStringContainsString('ops_per_sec', $stdout . $stderr);
    }

    /**
     * The planted file IS the file that used to be committed.
     *
     * tools/php/benchmark-baseline.json contained one `_comment` key and nothing
     * else for the whole release-candidate phase. The script skips
     * underscore-prefixed keys as metadata, so the baseline decoded to an empty
     * map, every benchmark printed NEW/OK, and the gate exited 0 — under a check
     * named "Performance Regression Check", on every pull request, while README.md
     * told readers the numbers were compared against a stored baseline.
     *
     * The second case here is the one that keeps this closed after the placeholder
     * is gone: a baseline full of real measurements under names no benchmark
     * answers to any more produces the identical all-NEW table, and that is what a
     * rename does on the day it lands.
     */
    #[Test]
    public function itRefusesABaselineThatMatchesNothingRatherThanCallingItAllNew(): void
    {
        $placeholder = $this->write('{"_comment": "Auto-generated benchmark baseline."}');

        [$status, $stdout, $stderr] = $this->runGate('--baseline=' . $placeholder, '--threshold=5');

        self::assertSame(
            1,
            $status,
            'RunBenchmarks.php reported success against a baseline holding no measurement at all. '
            . 'That is not a hypothetical: it is the file this repository shipped, and it is why a '
            . 'green performance check meant nothing for an entire release-candidate phase.',
        );
        self::assertStringContainsString('Nothing was compared', $stderr);
        self::assertStringContainsString('NEW', $stdout);

        $renamed = $this->write('{"hello_wrold": {"ops_per_sec": 1000000}}');

        [$status, , $stderr] = $this->runGate('--baseline=' . $renamed, '--threshold=5');

        self::assertSame(
            1,
            $status,
            'a baseline naming only benchmarks that no longer exist was accepted. A rename produces '
            . 'exactly this shape, so the gate would go quiet on the day of the rename and stay '
            . 'quiet until somebody noticed the table was all NEW.',
        );
        self::assertStringContainsString('Nothing was compared', $stderr);
    }

    /**
     * A baseline that is not there has verified nothing.
     *
     * This branch used to print the current numbers and exit 0, which meant a
     * workflow whose recording step wrote the baseline somewhere else — a renamed
     * temp directory, a typo in a path — got a green check from a comparison that
     * never happened. benchmark-regression.yml now passes the same path to two
     * steps, so that typo is one character away.
     */
    #[Test]
    public function itRefusesABaselinePathThatDoesNotExist(): void
    {
        $absent = sys_get_temp_dir() . '/pulsar-baseline-absent-' . bin2hex(random_bytes(6)) . '.json';

        [$status, , $stderr] = $this->runGate('--baseline=' . $absent, '--threshold=5');

        self::assertSame(
            1,
            $status,
            'a missing baseline was reported as success. "Nothing to compare against" and "within '
            . 'threshold" are not the same answer, and only one of them belongs on a green check.',
        );
        self::assertStringContainsString('No baseline found', $stderr);
    }

    /**
     * The control: measured against a baseline it beats, the gate passes.
     *
     * Scaling down by ten makes every current figure an improvement. Without this
     * case, a script that exited 1 unconditionally — the audited shape of
     * check_compliance_claims.php — would satisfy both refusals above.
     */
    #[Test]
    public function itPassesWhenNothingRegressed(): void
    {
        $baseline = $this->baselineScaledBy(0.1);

        [$status, $stdout] = $this->runGate('--baseline=' . $baseline, '--threshold=5');

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('within threshold', $stdout);
    }

    /**
     * Record a real baseline, then scale every figure by the given factor.
     *
     * @return string path to the scaled baseline
     */
    private function baselineScaledBy(float $factor): string
    {
        $recorded = sys_get_temp_dir() . '/pulsar-baseline-' . bin2hex(random_bytes(6)) . '.json';
        $this->written[] = $recorded;

        [$status, $stdout, $stderr] = $this->runGate('--baseline=' . $recorded, '--update-baseline');

        self::assertSame(0, $status, 'could not record a baseline: ' . $stdout . $stderr);

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($recorded), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNotSame([], $decoded, 'the recorded baseline is empty, so nothing would be compared');

        $scaled = [];

        /** @var mixed $entry */
        foreach ($decoded as $name => $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('ops_per_sec', $entry);
            self::assertIsNumeric($entry['ops_per_sec']);

            $scaled[$name] = ['ops_per_sec' => (float) $entry['ops_per_sec'] * $factor];
        }

        $path = sys_get_temp_dir() . '/pulsar-baseline-scaled-' . bin2hex(random_bytes(6)) . '.json';
        $this->written[] = $path;
        file_put_contents($path, json_encode($scaled, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Write a baseline document verbatim and register it for removal.
     */
    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . '/pulsar-baseline-' . bin2hex(random_bytes(6)) . '.json';
        $this->written[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runGate(string ...$arguments): array
    {
        return $this->runScript(self::SCRIPT, '--iterations=20', '--warmup=2', ...$arguments);
    }
}
