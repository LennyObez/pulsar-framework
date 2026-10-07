<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\TemporaryTree;

use function array_merge;
use function dirname;
use function fclose;
use function getenv;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Guards the boot smoke gate and the two CI steps that lean on it.
 *
 * ci.yml runs `php bin/pulsar list` on ubuntu and on windows and calls it the
 * guard against a boot regression — a route collision, a wiring fault, a bundled
 * extension capped to community because its signature no longer verifies. All of
 * that rests on one assumption nobody had tested: that a kernel which fails to
 * boot makes the CLI exit non-zero. If it ever exits 0 while printing an error,
 * the smoke gate becomes a decorative step in two jobs at once.
 *
 * The second case is not about booting at all. The cache-warmup step is written
 * as `if pulsar list | grep -q 'optimize:validate'; then … --strict; else …`, so
 * the day that command stops being registered the step stops validating and
 * silently takes the weaker branch, still green. Asserting the command is in the
 * listing is what keeps that conditional honest.
 */
#[CoversNothing]
#[GuardsGate(gate: 'bin/pulsar list', plants: 'a kernel that cannot boot, behind an exception handler that swallows the exit code')]
final class KernelBootSmokeGateTest extends TestCase
{
    private const string CLI = __DIR__ . '/../../../bin/pulsar';

    private ?TemporaryTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
        $this->tree = null;
    }

    /**
     * The planted defect: a project whose configuration throws while loading.
     *
     * `bin/pulsar` reads config/ from the working directory, so a tree with a
     * config file that raises is a boot fault of exactly the kind the smoke step
     * is named for — and the whole question is whether the process says so in its
     * exit status or only in its output.
     */
    #[Test]
    public function itFailsWhenTheKernelCannotBoot(): void
    {
        $tree = TemporaryTree::create('boot');
        $this->tree = $tree;

        $tree->write(
            'config/app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nthrow new RuntimeException('planted boot fault');\n",
        );

        [$status, $stdout, $stderr] = $this->runCli($tree->path);

        self::assertNotSame(
            0,
            $status,
            'bin/pulsar exited 0 on a kernel that could not boot. What ships on that silence: the '
            . 'boot smoke step in both the ubuntu and windows jobs stops catching route collisions, '
            . 'wiring faults and extensions capped to community — a framework that cannot start, '
            . 'merged under two green checkmarks.',
        );
        self::assertStringContainsString('planted boot fault', $stdout . $stderr);
    }

    /**
     * The control, and the assertion the cache-warmup step depends on.
     *
     * Booting the real repository is what the CI step does; if this cannot pass,
     * the negative case above proves nothing, because a CLI that always exits
     * non-zero is not a gate either.
     */
    #[Test]
    public function itBootsTheRepositoryAndRegistersTheCommandsCiBranchesOn(): void
    {
        [$status, $stdout, $stderr] = $this->runCli(dirname(__DIR__, 3));

        self::assertSame(0, $status, $stdout . $stderr);

        self::assertStringContainsString(
            'optimize:validate',
            $stdout,
            'optimize:validate is no longer registered. What ships on that silence: the cache-warmup '
            . 'step greps the command list for it and falls back to plain optimize/optimize:clear '
            . 'when it is absent — so the strict validation quietly stops running and the step stays '
            . 'green either way.',
        );
        self::assertStringContainsString(
            'i18n:slugs:lint',
            $stdout,
            'i18n:slugs:lint is no longer registered, so the localized-slug step runs a command that '
            . 'does not exist.',
        );
    }

    /**
     * Run `bin/pulsar list` in a working directory and report what happened.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCli(string $workingDirectory): array
    {
        $process = proc_open(
            [PHP_BINARY, self::CLI, 'list'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            // The base path is cleared rather than inherited: this process may be
            // running under one, and the planted tree must be judged on its own
            // configuration rather than on the repository's.
            array_merge(getenv(), ['PULSAR_BASE_PATH' => '']),
        );

        self::assertIsResource($process, 'could not start bin/pulsar');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
