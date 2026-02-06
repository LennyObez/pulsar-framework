<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server\Controller;

use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function exec;
use function implode;
use function is_array;
use function is_string;

use const PHP_BINARY;

use function preg_match;

use Pulsar\Api\Internal;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

/**
 * Handles POST actions for the benchmark dashboard API.
 *
 * Actions: run benchmarks, delete specific runs, clear all history.
 */
#[Internal]
final readonly class BenchmarkApiController
{
    public function __construct(
        private EventStoreInterface $store,
        private string $basePath,
    ) {}

    /**
     * POST /studio/api/benchmark/run — execute the benchmark command.
     */
    public function run(Request $_request): Response
    {
        $command = PHP_BINARY . ' ' . $this->basePath . '/bin/pulsar studio:console:bench --json 2>&1';
        /** @var list<string> $output */
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $outputText = implode("\n", array_map(strval(...), $output));

        if ($exitCode !== 0) {
            return Response::json(
                ['error' => 'Benchmark command failed', 'exit_code' => $exitCode, 'output' => $outputText],
                ResponseStatus::InternalServerError,
            );
        }

        return Response::json(['success' => true, 'output' => $outputText]);
    }

    /**
     * POST /studio/api/benchmark/delete — delete specific benchmark runs by run_id.
     */
    public function deleteRuns(Request $request): Response
    {
        $data = $request->json();

        if (!isset($data['run_ids']) || !is_array($data['run_ids'])) {
            return Response::json(
                ['error' => 'Missing or invalid run_ids array'],
                ResponseStatus::BadRequest,
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
                ResponseStatus::BadRequest,
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
     * POST /studio/api/benchmark/clear — delete all benchmark events.
     */
    public function clearHistory(Request $_request): Response
    {
        $deleted = $this->store->deleteByEventTypes(['benchmark.run', 'benchmark.profile']);

        return Response::json(['deleted' => $deleted]);
    }
}
