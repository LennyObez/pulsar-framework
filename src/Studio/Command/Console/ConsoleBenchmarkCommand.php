<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function array_keys;
use function array_map;
use function bin2hex;

use Closure;

use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_get_contents;
use function hrtime;
use function implode;
use function is_dir;
use function json_decode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;

use function ksort;
use function mkdir;
use function number_format;

use Override;

use const PHP_BINARY;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\BenchmarkProfilePayload;
use Pulsar\Studio\Console\Event\Payload\BenchmarkRunPayload;
use Pulsar\Support\AtomicFileWriter;

use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

use Throwable;

use function unlink;

/**
 * Studio benchmark command — runs the profile matrix and emits events.
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
     * @throws JsonException
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

        /** @var array<string, array{description: string, ini: array<string, string>, preload: bool}> $profiles */
        $profiles = json_decode($profilesContent, true, 512, JSON_THROW_ON_ERROR);

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

        ksort($profiles);

        $runId = bin2hex(random_bytes(16));
        $phpBinary = PHP_BINARY;
        $workerScript = $this->basePath . '/tools/bench/worker.php';
        $tempPreloadFile = $this->generatePreloadIfNeeded($profiles, $phpBinary, $output);

        $runStart = hrtime(true);
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        if (!$isJson) {
            $output->writeln('Pulsar Performance Profile Matrix');
            $output->writeln(sprintf('PHP %s (%s) | %s %s', PHP_VERSION, PHP_SAPI, PHP_OS_FAMILY, php_uname('m')));
            $output->writeln(str_repeat('=', 70));
            $output->writeln('');
        }

        foreach ($profiles as $name => $profile) {
            if ($profile['preload'] && $tempPreloadFile === null) {
                $failureCount++;
                $results[$name] = ['error' => 'preload generation failed'];

                if (!$isJson) {
                    $output->writeln(sprintf('  [skip] %s — preload unavailable', $name));
                }

                continue;
            }

            $metrics = $this->runWorker($name, $profile, $phpBinary, $workerScript, $tempPreloadFile);

            if ($metrics === null) {
                $failureCount++;
                $results[$name] = ['error' => 'worker failed'];

                if (!$isJson) {
                    $output->writeln(sprintf('  [fail] %s', $name));
                }

                continue;
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

        $totalDurationMs = (float) (hrtime(true) - $runStart) / 1_000_000.0;

        $runPayload = new BenchmarkRunPayload(
            runId: $runId,
            phpVersion: PHP_VERSION,
            phpSapi: PHP_SAPI,
            osPlatform: PHP_OS_FAMILY,
            osArch: php_uname('m'),
            profileCount: count($profiles),
            successCount: $successCount,
            failureCount: $failureCount,
            totalDurationMs: round($totalDurationMs, 2),
            profileNames: array_keys($profiles),
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
                $output->writeln('');
                $output->writeln(sprintf('Results saved to %s', $outputPath));
            }
        }

        // Clean up temp preload file
        if ($tempPreloadFile !== null && file_exists($tempPreloadFile)) {
            @unlink($tempPreloadFile);
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

        $output->writeln('');
        $output->writeln(sprintf(
            'Completed: %d/%d profiles in %.1f ms (run %s)',
            $successCount,
            count($profiles),
            $totalDurationMs,
            $runId,
        ));

        return ExitCode::Success->value;
    }

    /**
     * @param array<string, array{description: string, ini: array<string, string>, preload: bool}> $profiles
     */
    private function generatePreloadIfNeeded(array $profiles, string $phpBinary, OutputInterface $output): ?string
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

        $tempPreloadFile = sys_get_temp_dir() . '/pulsar_bench_preload_' . bin2hex(random_bytes(4)) . '.php';

        $preloadCmd = sprintf(
            '%s %s/bin/pulsar preload:dump --output=%s --no-meta 2>&1',
            escapeshellarg($phpBinary),
            escapeshellarg($this->basePath),
            escapeshellarg($tempPreloadFile),
        );

        $preloadOutput = [];
        $preloadExitCode = 0;
        exec($preloadCmd, $preloadOutput, $preloadExitCode);

        if ($preloadExitCode !== 0) {
            return null;
        }

        return $tempPreloadFile;
    }

    /**
     * @param array{description: string, ini: array<string, string>, preload: bool} $profile
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
        $iniFlags = [];

        foreach ($profile['ini'] as $key => $value) {
            $iniFlags[] = '-d';
            $iniFlags[] = sprintf('%s=%s', $key, $value);
        }

        if ($profile['preload'] && $tempPreloadFile !== null) {
            $iniFlags[] = '-d';
            $iniFlags[] = sprintf('opcache.preload=%s', $tempPreloadFile);
        }

        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($phpBinary),
            implode(' ', array_map('escapeshellarg', $iniFlags)),
            escapeshellarg($workerScript),
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $jsonLine = end($output);

        try {
            /** @var array{boot_us: int, warm_boot_us: int, iterations: int, memory_usage_kb: int, opcache_memory_kb: ?int, p50_us: int, p95_us: int, peak_rss_kb: int, rps: int} $metrics */
            $metrics = json_decode($jsonLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $metrics;
    }
}
