<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use RuntimeException;

use function fclose;
use function is_resource;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

/**
 * Runs memory benchmarks in fresh PHP processes for accurate peak measurement.
 *
 * Each scenario runs in an isolated process to ensure memory_get_peak_usage(true)
 * reflects only that scenario's peak, not contamination from prior benchmarks.
 */
final class MemoryProfileRunner
{
    private readonly string $phpBinary;
    private readonly string $scenarioDir;

    public function __construct(
        ?string $phpBinary = null,
        ?string $scenarioDir = null,
    ) {
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
        $this->scenarioDir = $scenarioDir ?? __DIR__ . '/../Scenarios';
    }

    /**
     * Run a memory profiling scenario and return the peak memory in bytes.
     *
     * @return array{peak_bytes: int, stdout: string}
     */
    public function run(string $scenarioName): array
    {
        $scriptPath = sprintf('%s/%s.php', $this->scenarioDir, $scenarioName);

        if (!file_exists($scriptPath)) {
            throw new RuntimeException(sprintf(
                'Memory scenario script not found: %s',
                $scriptPath,
            ));
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$this->phpBinary, $scriptPath],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf(
                'Failed to spawn memory profiling process for scenario: %s',
                $scenarioName,
            ));
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "Memory scenario '%s' failed (exit %d): %s",
                $scenarioName,
                $exitCode,
                $stderr ?: '(no stderr)',
            ));
        }

        $peakBytes = (int) trim($stdout ?: '0');

        return [
            'peak_bytes' => $peakBytes,
            'stdout' => $stdout ?: '',
        ];
    }

    /**
     * Assert that a scenario's peak memory is within budget.
     *
     * @param int $maxBytes Maximum allowed peak memory in bytes
     */
    public function assertWithinBudget(string $scenarioName, int $maxBytes): void
    {
        $result = $this->run($scenarioName);

        if ($result['peak_bytes'] > $maxBytes) {
            throw new RuntimeException(sprintf(
                "Memory budget exceeded for '%s': %d bytes (budget: %d bytes, over by %d bytes)",
                $scenarioName,
                $result['peak_bytes'],
                $maxBytes,
                $result['peak_bytes'] - $maxBytes,
            ));
        }
    }
}
