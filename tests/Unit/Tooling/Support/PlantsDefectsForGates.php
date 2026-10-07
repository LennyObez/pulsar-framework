<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_values;
use function bin2hex;
use function dirname;
use function fclose;
use function file_put_contents;
use function fwrite;
use function is_dir;
use function is_file;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

/**
 * Plants a defect where no gate that runs over this repository can reach it, and
 * runs the real gate against it.
 *
 * WHY THE FIXTURES ARE WRITTEN AT RUNTIME RATHER THAN COMMITTED
 *
 * A negative test for a static-analysis gate needs source that the gate refuses.
 * Committing that source under `tests/` puts it inside `tools/php/phpstan.neon`'s
 * `paths`, inside `.php-cs-fixer.dist.php`'s finder and inside composer's `tests/`
 * PSR-4 root — so the fixture would fail the very gates the rest of the suite has
 * to keep green. `tests/Unit/Tooling/data/broken-hash/` gets away with it only
 * because ForbidBrokenHashRule exempts every path under `tests/`; no other gate
 * here grants that, and asking each of them for an exemption would carve a hole in
 * the gate to make room for the test that proves the gate has no holes.
 *
 * So every fixture is written into a fresh directory under the system temp root,
 * outside every configured scan path, and deleted afterwards. Nothing is planted
 * in the working tree, which is also why none of these tests can leave a
 * half-restored file behind: there is no tracked file to restore. {@see
 * assertNothingWasLeftBehind()} holds that claim rather than stating it.
 *
 * WHY THE GATE IS RUN AND NOT IMPORTED
 *
 * The exit code is the whole product of a gate — CI branches on it and on nothing
 * else — so each gate is executed as a real subprocess with the configuration file
 * that `composer qa` and `.github/workflows/ci.yml` name. Deriving a cut-down
 * config would test a config that guards nothing.
 */
trait PlantsDefectsForGates
{
    /** @var list<string> */
    private array $plantedTrees = [];

    protected function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * A fresh empty directory outside every path any gate scans.
     */
    protected function plantTree(string $label): string
    {
        $path = sys_get_temp_dir() . '/pulsar-gate-' . $label . '-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path, 0o700, true), 'could not create fixture tree at ' . $path);

        $this->plantedTrees[] = $path;

        return $path;
    }

    /**
     * Writes one planted file into a tree and returns its absolute path.
     */
    protected function plantFile(string $tree, string $relative, string $contents): string
    {
        $path = $tree . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o700, true), 'could not create ' . $directory);
        }

        self::assertNotFalse(file_put_contents($path, $contents), 'could not write ' . $path);

        return $path;
    }

    /**
     * Runs a gate as a real subprocess from the repository root.
     *
     * @param list<string> $command argv, without the PHP binary
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function runGate(array $command, ?string $cwd = null): array
    {
        return $this->runCommand([PHP_BINARY, ...$command], cwd: $cwd);
    }

    /**
     * Runs any gate executable, not only a PHP one.
     *
     * @param list<string> $command full argv, including the executable
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function runCommand(array $command, ?string $stdin = null, ?string $cwd = null): array
    {
        // Descriptor 0 is opened and closed rather than inherited: under a parallel
        // runner the inherited stdin is the pipe the runner uses to feed its worker,
        // and handing a duplicate of that to an unrelated subprocess is a
        // coordination hazard for the sake of nothing.
        $process = proc_open(
            array_values($command),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $this->repositoryRoot(),
        );

        self::assertIsResource($process, 'could not start ' . ($command[0] ?? '<empty command>'));

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Removes every planted tree and asserts that removal succeeded.
     *
     * A verification artefact surviving the verification is the failure mode this
     * whole class of test exists to prevent, so the cleanup is asserted rather
     * than attempted.
     */
    protected function assertNothingWasLeftBehind(): void
    {
        $trees = $this->plantedTrees;
        $this->plantedTrees = [];

        foreach ($trees as $tree) {
            self::removeTree($tree);
            self::assertDirectoryDoesNotExist($tree, 'planted fixture tree survived the test');
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
