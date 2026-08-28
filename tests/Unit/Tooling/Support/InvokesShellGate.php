<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use function array_merge;
use function fclose;
use function getenv;
use function is_file;
use function is_string;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strtr;

use const PHP_OS_FAMILY;

/**
 * Runs a `.sh` merge gate as a real subprocess, the way CI runs it.
 *
 * Three of the merge gates are shell scripts (check-adr.sh, check-pr-size.sh,
 * check-pr-title-scope.sh) and each of them decides whether a pull request may
 * merge. Reimplementing their logic in PHP to test it would be testing the
 * reimplementation; what CI branches on is the exit code of `bash <script>`, so
 * that is what these tests drive.
 *
 * The interpreter is resolved rather than assumed. On Windows `bash` on PATH is
 * frequently WSL's interop shim, which boots a Linux VM, prints its own warnings
 * on stderr and takes tens of seconds — so Git for Windows' bash is preferred
 * explicitly, and PULSAR_BASH overrides both. When no POSIX shell can be found
 * the caller reports that as the reason rather than passing quietly: a gate test
 * that abstains is the very thing this suite exists to stop.
 */
trait InvokesShellGate
{
    /**
     * The POSIX shell to drive the gates with, or null when this machine has none.
     */
    protected function bashBinary(): ?string
    {
        $override = getenv('PULSAR_BASH');

        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                (getenv('ProgramFiles') ?: 'C:\\Program Files') . '\\Git\\bin\\bash.exe',
                (getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)') . '\\Git\\bin\\bash.exe',
                (getenv('ProgramW6432') ?: 'C:\\Program Files') . '\\Git\\bin\\bash.exe',
            ]
            : ['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run a shell gate inside a working tree, with the environment CI gives it.
     *
     * @param array<string, string> $environment variables the workflow sets for the step
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function runShellGate(string $bash, string $script, string $workingDirectory, array $environment): array
    {
        // The parent environment is inherited and then overridden, rather than
        // replaced: git, gh and the shell itself need PATH, HOME and (on Windows)
        // SystemRoot, and a gate handed an empty environment would fail for
        // reasons that have nothing to do with the defect under test.
        $inherited = getenv();

        $process = proc_open(
            [$bash, strtr($script, ['\\' => '/'])],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            array_merge($inherited, $environment),
        );

        self::assertIsResource($process, 'could not start ' . $bash);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
