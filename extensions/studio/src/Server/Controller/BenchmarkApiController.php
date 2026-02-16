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
use function is_string;
use function mkdir;
use function pclose;
use function popen;
use function preg_match;
use function sprintf;
use function trim;
use function unlink;
use function var_export;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

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

        // Clean stale files from previous runs
        @unlink($outputFile);
        @unlink($exitFile);
        @unlink($pidFile);
        @unlink($runnerFile);

        $binary = PHP_BINARY;
        $script = $this->basePath . '/bin/pulsar';

        // Write a temporary runner script. This avoids shell quoting issues
        // on Windows and reliably captures both output and exit code.
        $runnerCode = sprintf(
            "<?php\n\$c = 0;\nob_start();\nsystem(%s, \$c);\nfile_put_contents(%s, ob_get_clean());\nfile_put_contents(%s, (string) \$c);\n",
            var_export(sprintf('%s %s studio:console:bench --json 2>&1', $binary, $script), true),
            var_export($outputFile, true),
            var_export($exitFile, true),
        );
        file_put_contents($runnerFile, $runnerCode);

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: start /B launches detached process
            $cmd = sprintf('start "" /B "%s" "%s"', $binary, $runnerFile);
            $handle = popen($cmd, 'r');

            if ($handle !== false) {
                pclose($handle);
            }

            file_put_contents($pidFile, 'windows');
        } else {
            // Unix: launch in background, capture PID via $!
            $cmd = sprintf('%s %s & echo $!', $binary, $runnerFile);
            $handle = popen($cmd, 'r');

            if ($handle !== false) {
                $pid = trim((string) fgets($handle));
                pclose($handle);
                file_put_contents($pidFile, $pid);
            }
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

            // Clean up run files
            @unlink($pidFile);
            @unlink($exitFile);
            @unlink($outputFile);
            @unlink($runnerFile);

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
