<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console;

use Closure;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkProfilePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkRunPayload;
use Pulsar\Support\AtomicFileWriter;
use Random\RandomException;
use Throwable;

use function array_keys;
use function bin2hex;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function hrtime;
use function is_dir;
use function is_resource;
use function json_decode;
use function ksort;
use function mkdir;
use function number_format;
use function random_bytes;
use function sprintf;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

/**
 * Studio benchmark command: runs the profile matrix and emits events.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class ConsoleBenchmarkCommand extends Command
{
    /**
     * @param Closure(ConsoleEvent, null): void $emit
     */
    public function __construct(
        private readonly Closure $emit,
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:bench';
        $this->description = 'Run performance benchmarks and emit Studio events';
        $this->addOption('json', 'Output as JSON', 'j');
        $this->addOption('profile', 'Run a single profile by name', 'p');
        $this->addOption('output', 'Save results JSON to a file path');
    }

    /**
     * @throws JsonException If profile JSON cannot be decoded or results cannot be encoded
     * @throws RandomException If random_bytes() fails for run ID generation
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        /** @var string|null $singleProfile */
        $singleProfile = $input->getOption('profile');

        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');

        $profilesFile = $this->basePath . '/tools/bench/profiles.json';

        if (!file_exists($profilesFile)) {
            JsonOutputHelper::writeError($output, $isJson, 'profiles_not_found', 'Error: profiles.json not found');

            return ExitCode::Error->value;
        }

        $profilesContent = file_get_contents($profilesFile);

        if ($profilesContent === false) {
            JsonOutputHelper::writeError($output, $isJson, 'profiles_read_error', 'Error: could not read profiles.json');

            return ExitCode::Error->value;
        }

        /** @var array<string, array{description: string, ini: array<string, string>, preload: bool, optimize: bool, worker?: string}> $profiles */
        $profiles = json_decode($profilesContent, true, flags: JSON_THROW_ON_ERROR);

        if ($singleProfile !== null) {
            if (!isset($profiles[$singleProfile])) {
                JsonOutputHelper::writeError(
                    $output,
                    $isJson,
                    'profile_not_found',
                    sprintf('Error: profile "%s" not found', $singleProfile),
                );

                return ExitCode::Error->value;
            }
            $profiles = [$singleProfile => $profiles[$singleProfile]];
        }

        // Partition into non-optimized and optimized groups
        $unoptimized = [];
        $optimized = [];

        foreach ($profiles as $name => $profile) {
            if ($profile['optimize']) {
                $optimized[$name] = $profile;
            } else {
                $unoptimized[$name] = $profile;
            }
        }

        ksort($unoptimized);
        ksort($optimized);

        $runId = bin2hex(random_bytes(12));
        $phpBinary = PHP_BINARY;
        $workerScript = $this->basePath . '/tools/bench/worker.php';
        $allProfiles = $unoptimized + $optimized;
        $tempPreloadFile = $this->generatePreloadIfNeeded($allProfiles, $phpBinary);

        $runStart = hrtime(true);
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $skipCount = 0;

        if (!$isJson) {
            $output->writeln('Pulsar Performance Profile Matrix');
            $output->writeln(sprintf('PHP %s (%s) | %s %s', PHP_VERSION, PHP_SAPI, PHP_OS_FAMILY, php_uname('m')));
            $output->writeln(str_repeat('=', 70));
            $output->writeln();
        }

        // Phase 1: Non-optimized profiles
        if ($unoptimized !== []) {
            if (!$isJson) {
                $output->writeln('--- Non-optimized profiles ---');
            }

            foreach ($unoptimized as $name => $profile) {
                $this->executeProfile(
                    $name,
                    $profile,
                    $runId,
                    $phpBinary,
                    $workerScript,
                    $tempPreloadFile,
                    $isJson,
                    $output,
                    $results,
                    $successCount,
                    $failureCount,
                    $skipCount,
                );
            }

            if (!$isJson) {
                $output->writeln();
            }
        }

        // Phase 2 + 3: Optimized profiles
        if ($optimized !== []) {
            $optimizeFailed = false;

            if (!$isJson) {
                $output->writeln('--- Warming framework cache ---');
            }

            if ($this->runOptimize($phpBinary)) {
                if (!$isJson) {
                    $output->writeln('  [ok]   Framework cache warmed');
                    $output->writeln();
                    $output->writeln('--- Optimized profiles ---');
                }
            } else {
                $optimizeFailed = true;

                if (!$isJson) {
                    $output->writeln('  [fail] Framework cache could not be warmed');
                    $output->writeln();
                    $output->writeln('--- Optimized profiles ---');
                }
            }

            foreach ($optimized as $name => $profile) {
                if ($optimizeFailed) {
                    $skipCount++;
                    $results[$name] = ['error' => 'optimize command failed'];

                    if (!$isJson) {
                        $output->writeln(sprintf('  [skip] %s: optimize command failed', $name));
                    }

                    continue;
                }

                $this->executeProfile(
                    $name,
                    $profile,
                    $runId,
                    $phpBinary,
                    $workerScript,
                    $tempPreloadFile,
                    $isJson,
                    $output,
                    $results,
                    $successCount,
                    $failureCount,
                    $skipCount,
                );
            }

            if (!$isJson) {
                $output->writeln();
                $output->writeln('--- Clearing framework cache ---');
            }

            if ($this->runOptimizeClear($phpBinary)) {
                if (!$isJson) {
                    $output->writeln('  [ok]   Framework cache cleared');
                }
            } else {
                if (!$isJson) {
                    $output->writeln('  [warn] Framework cache could not be cleared');
                }
            }
        }

        $totalDurationMs = (float) (hrtime(true) - $runStart) / 1_000_000.0;

        $runPayload = new BenchmarkRunPayload(
            runId: $runId,
            phpVersion: PHP_VERSION,
            phpSapi: PHP_SAPI,
            osPlatform: PHP_OS_FAMILY,
            osArch: php_uname('m'),
            profileCount: count($allProfiles),
            successCount: $successCount,
            failureCount: $failureCount,
            skippedCount: $skipCount,
            totalDurationMs: round($totalDurationMs, 2),
            profileNames: array_keys($allProfiles),
        );

        try {
            ($this->emit)($runPayload, null);
        } catch (Throwable) {
            // Silently ignore emission errors
        }

        // Save results JSON if requested
        if ($outputPath !== null) {
            ksort($results);
            $outputDir = dirname($outputPath);

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0o755, true);
            }

            $resultsJson = json_encode(
                $results,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
            AtomicFileWriter::write($outputPath, $resultsJson . "\n");

            if (!$isJson) {
                $output->writeln();
                $output->writeln(sprintf('Results saved to %s', $outputPath));
            }
        }

        // Clean up temp preload file (best effort: a race with another process
        // touching the file is acceptable here, but we no longer suppress
        // warnings on the unlink itself).
        if ($tempPreloadFile !== null && is_file($tempPreloadFile)) {
            unlink($tempPreloadFile);
        }

        if ($isJson) {
            $data = [
                'run_id' => $runId,
                'profiles' => $results,
                'summary' => $runPayload->toArray(),
            ];

            $output->writeln(JsonOutputHelper::encode('studio:console:bench', true, $data));

            return ExitCode::Success->value;
        }

        $output->writeln();
        $completedSuffix = $skipCount > 0
            ? sprintf(' (%d skipped)', $skipCount)
            : '';
        $output->writeln(sprintf(
            'Completed: %d/%d profiles in %.1f ms%s (run %s)',
            $successCount,
            count($allProfiles),
            $totalDurationMs,
            $completedSuffix,
            $runId,
        ));

        return ExitCode::Success->value;
    }

    /**
     * @param array{description: string, ini: array<string, string>, preload: bool, optimize: bool, worker?: string} $profile
     * @param array<string, mixed> $results
     */
    private function executeProfile(
        string $name,
        array $profile,
        string $runId,
        string $phpBinary,
        string $workerScript,
        ?string $tempPreloadFile,
        bool $isJson,
        OutputInterface $output,
        array &$results,
        int &$successCount,
        int &$failureCount,
        int &$skipCount,
    ): void {
        if ($profile['preload'] && $tempPreloadFile === null) {
            $skipCount++;
            $results[$name] = ['error' => 'preload not supported on this platform'];

            if (!$isJson) {
                $output->writeln(sprintf('  [skip] %s: preloading requires Linux/macOS', $name));
            }

            return;
        }

        $metrics = $this->runWorker($name, $profile, $phpBinary, $workerScript, $tempPreloadFile);

        if ($metrics === null) {
            $failureCount++;
            $results[$name] = ['error' => 'worker failed'];

            if (!$isJson) {
                $output->writeln(sprintf('  [fail] %s', $name));
            }

            return;
        }

        $successCount++;
        $results[$name] = $metrics;

        $jitMode = $profile['ini']['opcache.jit'] ?? 'off';
        $jitEnabled = $jitMode !== 'off' && $jitMode !== '0';

        $payload = new BenchmarkProfilePayload(
            runId: $runId,
            profileName: $name,
            profileDescription: $profile['description'],
            bootUs: $metrics['boot_us'],
            warmBootUs: $metrics['warm_boot_us'],
            p50Us: $metrics['p50_us'],
            p95Us: $metrics['p95_us'],
            rps: $metrics['rps'],
            peakRssKb: $metrics['peak_rss_kb'],
            memoryUsageKb: $metrics['memory_usage_kb'],
            opcacheMemoryKb: $metrics['opcache_memory_kb'],
            iterations: $metrics['iterations'],
            jitEnabled: $jitEnabled,
            jitMode: $jitMode,
            preloadEnabled: $profile['preload'],
            optimizeEnabled: $profile['optimize'],
        );

        try {
            ($this->emit)($payload, null);
        } catch (Throwable) {
            // Silently ignore emission errors
        }

        if (!$isJson) {
            $opcDisplay = $metrics['opcache_memory_kb'] !== null
                ? number_format($metrics['opcache_memory_kb']) . ' KB'
                : '-';

            $output->writeln(sprintf(
                '  [ok]   %-25s  boot=%s us  warm=%s us  p50=%s us  p95=%s us  rps=%s  alloc=%s KB  rss=%s KB  opc=%s',
                $name,
                number_format($metrics['boot_us']),
                number_format($metrics['warm_boot_us']),
                number_format($metrics['p50_us']),
                number_format($metrics['p95_us']),
                number_format($metrics['rps']),
                number_format($metrics['memory_usage_kb']),
                number_format($metrics['peak_rss_kb']),
                $opcDisplay,
            ));
        }
    }

    /**
     * @param array<string, array{description: string, ini: array<string, string>, preload: bool, optimize: bool, worker?: string}> $profiles
     *
     * @throws RandomException If random_bytes() fails for temp file naming
     */
    private function generatePreloadIfNeeded(array $profiles, string $phpBinary): ?string
    {
        $needsPreload = false;

        foreach ($profiles as $profile) {
            if ($profile['preload']) {
                $needsPreload = true;

                break;
            }
        }

        if (!$needsPreload) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $tempPreloadFile = sys_get_temp_dir() . '/pulsar_bench_preload_' . bin2hex(random_bytes(4)) . '.php';

        // Use proc_open with argv array (no shell), avoiding any command
        // injection regardless of how $phpBinary or $tempPreloadFile are
        // constructed.
        $result = $this->runProcess([
            $phpBinary,
            $this->basePath . '/bin/pulsar',
            'preload:dump',
            '--output=' . $tempPreloadFile,
            '--no-meta',
        ]);

        if ($result['exit'] !== 0) {
            return null;
        }

        return $tempPreloadFile;
    }

    /**
     * Run a child process via proc_open with an argv array.
     *
     * Using proc_open with an array of arguments bypasses the shell
     * entirely. No shell metacharacters can be interpreted, no escaping
     * is required, and command injection is structurally impossible.
     *
     * @param list<string> $argv
     *
     * @return array{exit: int, output: list<string>}
     */
    private function runProcess(array $argv): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($argv, $descriptors, $pipes);

        if (!is_resource($proc)) {
            return ['exit' => -1, 'output' => []];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        $combined = $stdout . $stderr;
        $lines = $combined === '' ? [] : explode("\n", rtrim($combined, "\n"));

        return ['exit' => $exit, 'output' => $lines];
    }

    /**
     * @param array{description: string, ini: array<string, string>, preload: bool, optimize: bool, worker?: string} $profile
     *
     * @return array{boot_us: int, warm_boot_us: int, iterations: int, memory_usage_kb: int, opcache_memory_kb: ?int, p50_us: int, p95_us: int, peak_rss_kb: int, rps: int}|null
     */
    private function runWorker(
        string $name,
        array $profile,
        string $phpBinary,
        string $workerScript,
        ?string $tempPreloadFile,
    ): ?array {
        // Select worker script based on profile type
        $worker = $profile['worker'] ?? 'default';

        if ($worker === 'runtime') {
            $workerScript = $this->basePath . '/tools/bench/runtime-worker.php';
        }

        $iniFlags = [];

        foreach ($profile['ini'] as $key => $value) {
            $iniFlags[] = '-d';
            $iniFlags[] = sprintf('%s=%s', $key, $value);
        }

        if ($profile['preload'] && $tempPreloadFile !== null) {
            $iniFlags[] = '-d';
            $iniFlags[] = sprintf('opcache.preload=%s', $tempPreloadFile);
        }

        // Build argv: [phpBinary, -d key=val, -d key=val, ..., workerScript]
        $argv = array_merge([$phpBinary], $iniFlags, [$workerScript]);

        $result = $this->runProcess($argv);

        if ($result['exit'] !== 0 || $result['output'] === []) {
            return null;
        }

        $jsonLine = end($result['output']);

        try {
            /** @var array{boot_us: int, warm_boot_us: int, iterations: int, memory_usage_kb: int, opcache_memory_kb: ?int, p50_us: int, p95_us: int, peak_rss_kb: int, rps: int} $metrics */
            $metrics = json_decode($jsonLine, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $metrics;
    }

    private function runOptimize(string $phpBinary): bool
    {
        $result = $this->runProcess([
            $phpBinary,
            $this->basePath . '/bin/pulsar',
            'optimize',
        ]);

        return $result['exit'] === 0;
    }

    private function runOptimizeClear(string $phpBinary): bool
    {
        $result = $this->runProcess([
            $phpBinary,
            $this->basePath . '/bin/pulsar',
            'optimize:clear',
        ]);

        return $result['exit'] === 0;
    }
}
