<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Benchmark\Support\MemoryProfileRunner;
use RuntimeException;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function rtrim;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the Tier A memory peak budgets.
 *
 * Three scenarios (anonymous, authenticated, compliance) declare peak-memory
 * budgets of 2, 4 and 6 MB, enforced through {@see MemoryProfileRunner} by the
 * MemoryProfileBench subjects that `composer bench:ci` runs. A memory budget is
 * the kind of number that quietly stops being enforced: the measurement arrives
 * as text on a pipe, and text that is not a number casts to zero, which is under
 * every budget there is.
 *
 * So the refusals are planted here rather than assumed: a scenario that overruns,
 * a scenario that reports nothing, and a scenario that dies. Each must be a
 * failure, and the first two used not to be distinguishable from a very frugal
 * request.
 *
 * WORTH KNOWING, and not fixable from a test: the ci.yml step named "Tier A: Run
 * memory peak budgets" runs the three scenario scripts directly with their output
 * sent to /dev/null. Those scripts print a peak and exit 0; they carry no budget.
 * That step therefore catches a scenario that CRASHES and nothing else — the
 * budgets it is named for are enforced one step earlier, by phpbench, through the
 * runner tested here.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tests/Benchmark/Scenarios/memory_anonymous.php', plants: 'a scenario overrunning its peak-memory budget, one reporting no measurement at all, and one that cannot run')]
#[GuardsGate(gate: 'tests/Benchmark/Scenarios/memory_authenticated.php', plants: 'the same three refusals, driven through the MemoryProfileRunner all three scenarios share')]
#[GuardsGate(gate: 'tests/Benchmark/Scenarios/memory_compliance.php', plants: 'the same three refusals, driven through the MemoryProfileRunner all three scenarios share')]
final class MemoryBudgetGateTest extends TestCase
{
    private string $scenarioDir = '';

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->scenarioDir = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'pulsar-memory-gate-' . bin2hex(random_bytes(6));

        if (!mkdir($this->scenarioDir, 0o700, true) && !is_dir($this->scenarioDir)) {
            self::fail('could not create the scenario directory at ' . $this->scenarioDir);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];

        if (is_dir($this->scenarioDir)) {
            rmdir($this->scenarioDir);
        }
    }

    /**
     * The planted defect: a request that allocates far past its budget.
     */
    #[Test]
    public function itFailsWhenAScenarioOverrunsItsBudget(): void
    {
        // 8 MB of string, measured with real_usage true, against a 1 MB budget.
        $this->scenario('overrun', '$held = str_repeat("x", 8 * 1024 * 1024); echo memory_get_peak_usage(true);');

        $refusal = $this->refusalFrom('overrun', 1_048_576);

        self::assertNotNull(
            $refusal,
            'MemoryProfileRunner accepted a scenario eight times over its budget. What ships on that '
            . 'silence: the per-request memory ceilings for the anonymous, authenticated and '
            . 'compliance paths stop being enforced, and a request that allocates its way through a '
            . 'worker\'s memory limit reaches production having passed a budget nobody was checking.',
        );
        self::assertStringContainsString('Memory budget exceeded', $refusal->getMessage());
        self::assertStringContainsString('over by', $refusal->getMessage());
    }

    /**
     * The vacuous pass, planted: a scenario that reports no measurement at all.
     *
     * This is the failure mode that cannot be seen in a green log. A scenario
     * whose output is a warning, or empty because a fatal happened after the
     * shutdown handler, used to be read as a peak of zero bytes — under every
     * budget in the suite, and reported as the most frugal run ever recorded.
     */
    #[Test]
    public function itFailsWhenAScenarioReportsNoMeasurement(): void
    {
        $this->scenario('silent', 'echo "";');

        $refusal = $this->refusalFrom('silent', 1_048_576);

        self::assertNotNull(
            $refusal,
            'MemoryProfileRunner read a scenario that measured nothing as a pass. What ships on that '
            . 'silence: every memory budget in Tier A satisfied by a scenario that printed nothing, '
            . 'because "nothing" casts to zero bytes and zero is under every ceiling — a hard gate '
            . 'reporting the absence of a measurement as the best possible one.',
        );
        self::assertStringContainsString('reported no peak measurement', $refusal->getMessage());
    }

    /**
     * A scenario that dies is not a scenario that used no memory.
     */
    #[Test]
    public function itFailsWhenAScenarioCannotRun(): void
    {
        $this->scenario('broken', 'fwrite(STDERR, "boot failed"); exit(1);');

        $refusal = $this->refusalFrom('broken', 1_048_576);

        self::assertNotNull(
            $refusal,
            'MemoryProfileRunner treated a scenario that exited non-zero as a measurement. What ships '
            . 'on that silence: a boot regression severe enough to abort the scenario, recorded as a '
            . 'memory budget met.',
        );
        self::assertStringContainsString('failed (exit 1)', $refusal->getMessage());
    }

    /**
     * The control: a scenario inside its budget passes.
     *
     * Without it the three refusals above are equally explained by a runner that
     * refuses everything, which would fail Tier A on every change until someone
     * deleted the budgets.
     */
    #[Test]
    public function itPassesWhenTheScenarioIsWithinBudget(): void
    {
        $this->scenario('frugal', 'echo memory_get_peak_usage(true);');

        $runner = new MemoryProfileRunner(scenarioDir: $this->scenarioDir);
        $runner->assertWithinBudget('frugal', 64 * 1024 * 1024);

        $measured = $runner->run('frugal');

        self::assertGreaterThan(
            0,
            $measured['peak_bytes'],
            'the control scenario reported a zero peak, so this test proves nothing about budgets',
        );
    }

    /**
     * Write a scenario script the runner will execute in its own process.
     */
    private function scenario(string $name, string $body): void
    {
        $path = $this->scenarioDir . DIRECTORY_SEPARATOR . $name . '.php';
        $this->written[] = $path;

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
    }

    /**
     * Run a scenario against a budget and return the refusal, or null if it passed.
     */
    private function refusalFrom(string $scenario, int $budgetBytes): ?RuntimeException
    {
        $runner = new MemoryProfileRunner(scenarioDir: $this->scenarioDir);

        // Nothing is asserted inside the try: PHPUnit's AssertionFailedError
        // descends from RuntimeException, so an assertion failing in here would be
        // caught and inspected as though the subject had thrown it.
        try {
            $runner->assertWithinBudget($scenario, $budgetBytes);
        } catch (RuntimeException $thrown) {
            return $thrown;
        }

        return null;
    }
}
