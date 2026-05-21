<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function array_filter;
use function array_sum;
use function array_values;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function is_array;
use function is_dir;
use function is_numeric;
use function is_resource;
use function is_string;
use function mkdir;
use function preg_match;
use function sprintf;
use function trim;
use function unlink;
use function var_export;

use const PHP_BINARY;

/**
 * Handles benchmark dashboard API actions.
 *
 * Benchmarks run as background processes so the single-threaded PHP
 * built-in server remains responsive to other requests (new tabs, etc.).
 * The frontend polls GET /studio/api/benchmark/status for completion.
 */
#[Internal]
final readonly class BenchmarkApiController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private string $runDir;

    public function __construct(
        private EventStoreInterface $store,
        private DashboardAggregator $aggregator,
        private string $basePath,
    ) {
        $this->runDir = $this->basePath . '/storage/studio/bench';
    }

    /**
     * POST /studio/api/benchmark/run: start benchmark as a background process.
     *
     * Writes a temporary PHP runner script that executes the benchmark,
     * captures output to a file, and writes the exit code to another file.
     * The runner is launched detached so the HTTP response returns immediately.
     */
    public function run(ServerRequestInterface $_request): Response
    {
        $pidFile = $this->runDir . '/bench.pid';
        $outputFile = $this->runDir . '/bench.out';
        $exitFile = $this->runDir . '/bench.exit';
        $runnerFile = $this->runDir . '/bench-runner.php';

        // Reject if a benchmark is already running
        if (file_exists($pidFile) && $this->isProcessRunning($pidFile)) {
            return Response::json(
                ['error' => 'A benchmark is already running'],
                ResponseStatus::Conflict->value,
            );
        }

        // Ensure run directory exists
        if (!is_dir($this->runDir)) {
            mkdir($this->runDir, 0o750, true);
        }

        // Clean stale files from previous runs (best-effort: a file may
        // legitimately not exist between runs, so check before unlinking
        // rather than suppressing the warning).
        foreach ([$outputFile, $exitFile, $pidFile, $runnerFile] as $stale) {
            if (is_file($stale)) {
                unlink($stale);
            }
        }

        $binary = PHP_BINARY;
        $script = $this->basePath . '/bin/pulsar';

        // Write a temporary runner script. The runner uses proc_open with
        // an argv array (no shell), which prevents shell metacharacter
        // injection regardless of how the binary path is constructed.
        $runnerCode = sprintf(
            "<?php\n"
            . "\$proc = proc_open([%s, %s, 'studio:console:bench', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], \$pipes);\n"
            . "if (!is_resource(\$proc)) { file_put_contents(%s, '255'); exit(255); }\n"
            . "\$out = stream_get_contents(\$pipes[1]) . stream_get_contents(\$pipes[2]);\n"
            . "fclose(\$pipes[1]); fclose(\$pipes[2]);\n"
            . "\$exit = proc_close(\$proc);\n"
            . "file_put_contents(%s, \$out);\n"
            . "file_put_contents(%s, (string) \$exit);\n",
            var_export($binary, true),
            var_export($script, true),
            var_export($exitFile, true),
            var_export($outputFile, true),
            var_export($exitFile, true),
        );
        file_put_contents($runnerFile, $runnerCode);

        // Launch the runner script in the background using proc_open with
        // an argv array. This bypasses the shell entirely and prevents any
        // command injection regardless of binary or runnerFile content.
        $argv = [$binary, $runnerFile];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($argv, $descriptors, $pipes);

        if (is_resource($proc)) {
            // Close stdin so the child does not block waiting for input
            fclose($pipes[0]);

            $procStatus = proc_get_status($proc);
            $childPid = (string) $procStatus['pid'];

            // Detach: do not wait for the child. proc_close would block
            // until completion, so we leak the handle intentionally and
            // record the PID so status() can poll the exit file instead.
            file_put_contents($pidFile, $childPid);
        } else {
            file_put_contents($pidFile, 'failed');
            file_put_contents($exitFile, '255');
        }

        return Response::json(['started' => true]);
    }

    /**
     * GET /studio/api/benchmark/status: poll benchmark completion.
     */
    public function status(ServerRequestInterface $_request): Response
    {
        $pidFile = $this->runDir . '/bench.pid';
        $outputFile = $this->runDir . '/bench.out';
        $exitFile = $this->runDir . '/bench.exit';
        $runnerFile = $this->runDir . '/bench-runner.php';

        // No benchmark has been started
        if (!file_exists($pidFile)) {
            return Response::json(['running' => false, 'completed' => false]);
        }

        // Check if exit file exists: means process finished
        if (file_exists($exitFile)) {
            $exitCode = (int) trim((string) file_get_contents($exitFile));
            $output = file_exists($outputFile) ? (string) file_get_contents($outputFile) : '';

            // Clean up run files. Each path is constructed under our own
            // $this->runDir at the top of this method, so the unlink target
            // is always within a server-controlled directory.
            foreach ([$pidFile, $exitFile, $outputFile, $runnerFile] as $runFile) {
                if (is_file($runFile)) {
                    unlink($runFile);
                }
            }

            if ($exitCode !== 0) {
                return Response::json([
                    'running' => false,
                    'completed' => true,
                    'success' => false,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);
            }

            return Response::json([
                'running' => false,
                'completed' => true,
                'success' => true,
                'output' => $output,
            ]);
        }

        // Still running
        return Response::json(['running' => true, 'completed' => false]);
    }

    /**
     * POST /studio/api/benchmark/delete: delete specific benchmark runs.
     */
    public function deleteRuns(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = (array) $request->getParsedBody();

        if (!isset($data['run_ids']) || !is_array($data['run_ids'])) {
            return Response::json(
                ['error' => 'Missing or invalid run_ids array'],
                ResponseStatus::BadRequest->value,
            );
        }

        /** @var list<string> $runIds */
        $runIds = array_values(array_filter(
            $data['run_ids'],
            static fn(mixed $id): bool => is_string($id) && preg_match('/^[a-f0-9]{1,64}$/', $id) === 1,
        ));

        if ($runIds === []) {
            return Response::json(
                ['error' => 'No valid run_ids provided (expected lowercase hex, 1-64 chars)'],
                ResponseStatus::BadRequest->value,
            );
        }

        $counts = [];
        foreach ($runIds as $runId) {
            $counts[] = $this->store->deleteByPayloadKey('benchmark.profile', '$.run_id', $runId);
            $counts[] = $this->store->deleteByPayloadKey('benchmark.run', '$.run_id', $runId);
        }

        return Response::json(['deleted' => array_sum($counts)]);
    }

    /**
     * POST /studio/api/benchmark/clear: delete all benchmark events.
     */
    public function clearHistory(ServerRequestInterface $_request): Response
    {
        $deleted = $this->store->deleteByEventTypes(['benchmark.run', 'benchmark.profile']);

        return Response::json(['deleted' => $deleted]);
    }

    /**
     * GET /studio/api/benchmark/profiles: fetch profiles for a specific run.
     */
    public function profiles(ServerRequestInterface $request): Response
    {
        $runId = $request->getQueryParams()['run_id'] ?? '';

        if (!is_string($runId) || $runId === '' || preg_match('/^[a-f0-9]{1,64}$/', $runId) !== 1) {
            return Response::json(
                ['error' => 'Missing or invalid run_id (expected lowercase hex, 1-64 chars)'],
                ResponseStatus::BadRequest->value,
            );
        }

        $profiles = $this->aggregator->benchmarkProfiles($runId);

        return Response::json(['profiles' => $profiles]);
    }

    private function isProcessRunning(string $pidFile): bool
    {
        $pid = trim((string) file_get_contents($pidFile));

        if ($pid === 'windows') {
            // On Windows, track via exit file presence
            return !file_exists($this->runDir . '/bench.exit');
        }

        if ($pid === '' || !is_numeric($pid)) {
            return false;
        }

        // POSIX: signal 0 checks if process exists without sending a signal
        if (function_exists('posix_kill')) {
            return posix_kill((int) $pid, 0);
        }

        return false;
    }
}
