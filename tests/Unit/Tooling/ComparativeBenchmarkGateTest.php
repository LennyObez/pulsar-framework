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
 * benchmark-regression.yml runs benchmarks/Comparative/RunBenchmarks.php against
 * a committed baseline on every pull request touching src/, extensions/ or
 * benchmarks/, and refuses anything more than 5% slower. Nothing had ever handed
 * it a regression to refuse, so the three outcomes that matter are planted here:
 * a regression, a baseline that cannot be trusted, and an honest run.
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
 * WORTH KNOWING, and outside what a test can repair: tools/php/benchmark-baseline.json
 * as committed contains only its `_comment` key. Every benchmark therefore reports
 * NEW/OK on a pull request and the gate exits 0 having compared nothing. The
 * comparison below is real; the data the workflow feeds it is not yet.
 */
#[CoversNothing]
#[GuardsGate(gate: 'benchmarks/Comparative/RunBenchmarks.php', plants: 'a benchmark regressing past the 5% threshold, and a malformed baseline entry that must be refused rather than compared against null')]
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
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runGate(string ...$arguments): array
    {
        return $this->runScript(self::SCRIPT, '--iterations=20', '--warmup=2', ...$arguments);
    }
}
