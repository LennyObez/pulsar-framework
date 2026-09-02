<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Support\BootBenchmarkKernel;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;
use RuntimeException;

use function assert;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function realpath;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Guards the Tier A performance gate: the budgets, and the machinery under them.
 *
 * The audited defect was not a slow benchmark. It was a benchmark that measured
 * nothing — `benchKernelBootAndHandle()` asserted under 500 microseconds while
 * executing ZERO of the 51 wirings, inside a job called "Tier A: Performance
 * Budgets (Hard Gate)". Three separate things had to be true for that gate to
 * mean anything again, and none of the three had a test:
 *
 *   1. phpbench must actually FAIL when a subject misses its #[Assert] budget.
 *      77 budgets are declared across tests/Benchmark; if the assertion machinery
 *      is misconfigured, all 77 are decoration.
 *   2. The kernel under measurement must really be a booted one — the repair for
 *      the audited defect, which is one constructor argument away from returning.
 *   3. The workflow step must let phpbench's non-zero exit reach the job. It pipes
 *      into `tee`, and without pipefail the step's status is tee's, which is
 *      always 0.
 *
 * Each of the three is planted and observed refusing below. The fixtures for (1)
 * live under Fixture/PerformanceBudget/ rather than in tests/Benchmark, so that a
 * deliberately unmeetable budget can exist in this repository without turning the
 * real Tier A job red.
 */
#[CoversNothing]
#[GuardsGate(gate: 'composer bench:ci', plants: 'a subject missing its declared #[Assert] budget, a kernel benchmark that ran no wirings, and a Tier A job whose pipefail shell has been removed so tee swallows phpbench exit 2')]
final class PerformanceBudgetGateTest extends TestCase
{
    use InvokesCiScript;

    private const string PHPBENCH = __DIR__ . '/../../../vendor/bin/phpbench';
    private const string CONFIG = __DIR__ . '/../../../tools/php/phpbench.json';
    private const string WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

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
     * The planted regression: a subject three orders of magnitude over its budget.
     */
    #[Test]
    public function itFailsWhenASubjectMissesItsDeclaredBudget(): void
    {
        [$status, $stdout, $stderr] = $this->runBenchmarks('Breached');

        self::assertNotSame(
            0,
            $status,
            'phpbench reported success on a subject 2000x over its declared budget. What ships on '
            . 'that silence: all 77 #[Assert] budgets across tests/Benchmark become decoration, and '
            . '"Tier A: Performance Budgets (Hard Gate)" goes green on any regression at all — the '
            . 'audited defect restored, with the numbers still printed in the job summary as though '
            . 'they had been checked.' . $stdout . $stderr,
        );

        // The code, not just its sign: CI distinguishes a breached budget (2) from
        // a runner that could not start (1), and a step checking only for 1 would
        // walk past every failed assertion.
        self::assertSame(2, $status, $stdout . $stderr);
    }

    /**
     * The control: the same runner, a budget that is met, exit 0.
     */
    #[Test]
    public function itPassesWhenTheSubjectIsWithinItsBudget(): void
    {
        [$status, $stdout, $stderr] = $this->runBenchmarks('Within');

        self::assertSame(0, $status, $stdout . $stderr);
    }

    /**
     * The audited defect itself, planted: a kernel that ran no wirings.
     *
     * `new Kernel()` is the exact shape both boot subjects used while the gate was
     * asleep. `Kernel::boot()` gates the whole wiring loop behind a non-null
     * ConfigManager, so this kernel constructs a container, matches one route and
     * returns — measuring about 200 microseconds of object construction under a
     * budget written for a framework boot.
     */
    #[Test]
    public function itRefusesToBenchmarkAKernelThatRanNoWirings(): void
    {
        $request = new ServerRequest(method: 'GET', uri: BootBenchmarkKernel::ROUTE);

        $unwired = new Kernel();
        $unwired->router()->get(BootBenchmarkKernel::ROUTE, static fn(): Response => Response::text('ok'));
        $unwired->handle($request);

        $factory = new BootBenchmarkKernel();
        $refusal = null;

        // The refusal is caught rather than declared with expectException, and
        // nothing is asserted inside the try: PHPUnit's own AssertionFailedError
        // descends from RuntimeException, so a self::fail() in there would be
        // caught by the same handler and inspected as though it were the subject's
        // answer.
        try {
            $factory->assertWiringsRan($unwired);
        } catch (RuntimeException $thrown) {
            $refusal = $thrown;
        }

        self::assertNotNull(
            $refusal,
            'BootBenchmarkKernel::assertWiringsRan() accepted a kernel built without a '
            . 'ConfigManager. What ships on that silence: the audited defect exactly — boot '
            . 'benchmarks measuring object construction instead of a boot, passing their budgets '
            . 'with headroom while being structurally incapable of seeing a boot regression, '
            . 'because a benchmark that stops exercising its subject gets FASTER and so its '
            . 'budget passes harder the more broken it is.',
        );
        self::assertStringContainsString('not measuring a boot', $refusal->getMessage());
        self::assertStringContainsString('ConfigWiring', $refusal->getMessage());
    }

    /**
     * The control: the kernel the benchmarks really build passes the same check.
     *
     * Without it, the refusal above is equally explained by a check that refuses
     * every kernel — which would fail the Tier A job for everyone and get deleted
     * within a day.
     */
    #[Test]
    public function itAcceptsTheKernelTheBenchmarksActuallyMeasure(): void
    {
        $factory = new BootBenchmarkKernel();
        $request = new ServerRequest(method: 'GET', uri: BootBenchmarkKernel::ROUTE);

        $kernel = $factory->bootAndHandle($request);
        $factory->assertWiringsRan($kernel);

        // Not a formality: the profile is the kernel's own record that a boot
        // happened, and it is what the fourth clause of assertWiringsRan() reads
        // to tell a cold boot from a cached one.
        self::assertNotNull(
            $kernel->bootProfile(),
            'the benchmarked kernel reports no boot profile, so nothing here observed a boot',
        );
    }

    /**
     * The third leg: phpbench's exit code has to survive the pipe into `tee`.
     *
     * GitHub's implicit shell is `bash -e {0}` WITHOUT pipefail, so a step written
     * as `composer bench:ci | tee file` exits with tee's status — always 0 — and
     * the hard gate cannot fail whatever phpbench decides. The repair is one line:
     * `shell: bash` on the job, which is `bash --noprofile --norc -eo pipefail`.
     *
     * The check is applied twice: to the workflow as committed, and to the same
     * text with that one line taken out. A checker that could not tell the two
     * apart would be reporting the repair rather than verifying it.
     */
    #[Test]
    public function itKeepsPipefailOverTheBenchmarkPipeline(): void
    {
        $workflow = (string) file_get_contents(self::WORKFLOW);
        $job = $this->tierAJob($workflow);

        self::assertStringContainsString(
            '| tee',
            $job,
            'the Tier A job no longer pipes its benchmark output; re-derive this check against '
            . 'whatever it does now, because the pipefail requirement below was written for a pipe.',
        );

        self::assertTrue(
            $this->declaresPipefailShell($job),
            'The Tier A job pipes phpbench through tee without declaring `shell: bash`. What ships '
            . 'on that silence: the implicit `bash -e {0}` hands the step tee\'s exit status instead '
            . 'of phpbench\'s, so a breached budget prints in the log, lands in the job summary, and '
            . 'passes — a job named "Hard Gate" that cannot fail on a performance budget.',
        );

        $reverted = (string) preg_replace('/\n *shell: bash\n/', "\n", $job, 1);

        self::assertNotSame($job, $reverted, 'the repair was not found, so removing it proved nothing');
        self::assertFalse(
            $this->declaresPipefailShell($reverted),
            'the check passes on a job with the pipefail shell removed, so it is not checking for it',
        );
    }

    /**
     * Extract the Tier A job block from the workflow.
     */
    private function tierAJob(string $workflow): string
    {
        $matched = preg_match('/\n  php-benchmark-tier-a:\n(?:.*\n)*?(?=\n  [a-z0-9-]+:\n)/', $workflow, $matches);

        self::assertSame(
            1,
            $matched,
            'the php-benchmark-tier-a job is not in ci.yml under that name; this gate test must be '
            . 'pointed at whatever replaced it rather than quietly measuring nothing.',
        );

        return $matches[0];
    }

    /**
     * Whether the job block asks for the shell that carries a pipeline's failure.
     *
     * Comments are stripped first. The word "pipefail" appears in this job's own
     * explanation of why the line is there, and a check satisfied by a comment
     * about a control is the shape this whole exercise exists to remove.
     */
    private function declaresPipefailShell(string $job): bool
    {
        $withoutComments = (string) preg_replace('/^\s*#.*$/m', '', $job);

        return preg_match('/^\s*shell:\s*bash\s*$/m', $withoutComments) === 1;
    }

    /**
     * Run one fixture directory of benchmarks under the repository's own phpbench
     * configuration, and return what the runner said.
     *
     * The real config is reused rather than reinvented so that the pinned child
     * interpreter (`runner.php_config`: OPcache on, JIT off, Xdebug off) applies
     * here too — the settings that make a budget mean the same thing on every
     * machine. Only the two paths are redirected at the fixture.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runBenchmarks(string $fixture): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents(self::CONFIG), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        $bootstrap = realpath(__DIR__ . '/../../../tools/php/bootstrap.php');
        $path = realpath(__DIR__ . '/Fixture/PerformanceBudget/' . $fixture);

        self::assertIsString($bootstrap);
        self::assertIsString($path, 'the ' . $fixture . ' benchmark fixture is missing');

        $decoded['runner.bootstrap'] = $bootstrap;
        $decoded['runner.path'] = $path;

        $configPath = sys_get_temp_dir() . '/pulsar-phpbench-' . bin2hex(random_bytes(6)) . '.json';
        $this->written[] = $configPath;
        file_put_contents($configPath, json_encode($decoded, JSON_THROW_ON_ERROR));

        // The flags `composer bench:ci` uses, so what is under test is the gate CI
        // runs rather than a friendlier invocation of the same tool.
        return $this->runScript(
            self::PHPBENCH,
            'run',
            '--config=' . $configPath,
            '--report=default',
            '--progress=none',
        );
    }
}
