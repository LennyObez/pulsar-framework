<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use function array_values;
use function fclose;
use function is_dir;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Runs a tools/ci script as a real subprocess and returns what it said.
 *
 * The CI gates are scripts, and a gate is worth exactly its own correctness, so
 * each of them is tested by running it rather than by importing it. Four test
 * classes were doing that the same way; the process plumbing lives here once.
 *
 * Running it for real also tests the part that matters and cannot be tested any
 * other way: the exit code. CI branches on it, and a gate that exits 2 on a typo
 * in its own invocation would be skipped by a step that only checks for 1.
 */
trait InvokesCiScript
{
    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function runScript(string $script, string ...$arguments): array
    {
        // array_values, because proc_open wants a list and unpacking a variadic
        // does not guarantee one — a named argument would give it a string key.
        $command = array_values([PHP_BINARY, $script, ...$arguments]);

        // Descriptor 0 gets its own pipe and is closed at once, rather than left
        // unspecified. An unspecified descriptor is INHERITED, and under a
        // parallel test runner the inherited stdin is the pipe the runner uses to
        // send its worker the next command. Handing a duplicate of that to an
        // unrelated subprocess is a coordination hazard for the sake of nothing:
        // these scripts read no input, so they should be given none.
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            is_dir(__DIR__) ? __DIR__ : null,
        );

        self::assertIsResource($process, 'could not start ' . $script);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
