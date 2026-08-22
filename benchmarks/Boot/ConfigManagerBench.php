<?php

declare(strict_types=1);

namespace Pulsar\Benchmark\Boot;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Config\ConfigManager;

/**
 * PERF-BENCH-02 (external audit): boot-time cost of `ConfigManager::load()`.
 *
 * The audit flagged that ConfigManager walks a cascade of `is_file()` calls
 * across every optional section (i18n, event, database, …) on every boot.
 * For FrankenPHP / Swoole worker startups that's amortised; for FPM that's
 * per-request. This benchmark measures the steady-state cost so the
 * precompile-cache work (#118 PERF-HOT-03) has a concrete baseline to beat.
 *
 * Scaffold stage: setUp builds a representative app config directory in a
 * temp dir; the load() subject reports its baseline. The Subject method
 * runs in steady state (Revs * Iterations) so the result is stable.
 *
 * Run via:
 *   composer bench -- --filter=ConfigManagerBench
 */
#[BeforeMethods('setUp')]
#[Revs(20)]
#[Iterations(5)]
#[Warmup(1)]
final class ConfigManagerBench
{
    private string $configPath;

    private string $envFilePath;

    public function setUp(): void
    {
        // Build a representative config tree in a temp dir so the bench is
        // hermetic and reproducible (no dependency on the host's config/ layout).
        $this->configPath = sys_get_temp_dir() . '/pulsar-bench-config-' . uniqid('', true);
        $this->envFilePath = $this->configPath . '/.env';

        if (!is_dir($this->configPath)) {
            mkdir($this->configPath, 0o755, true);
        }

        file_put_contents($this->envFilePath, "APP_ENV=production\nAPP_KEY=base64:" . base64_encode(random_bytes(32)) . "\n");

        // Mandatory sections (app, observability, security).
        file_put_contents($this->configPath . '/app.php', '<?php return ["name" => "BenchApp", "debug" => false];');
        file_put_contents($this->configPath . '/observability.php', '<?php return [];');
        file_put_contents($this->configPath . '/security.php', '<?php return [];');

        // Optional sections that trigger the cascade is_file() checks.
        file_put_contents($this->configPath . '/i18n.php', '<?php return [];');
        file_put_contents($this->configPath . '/event.php', '<?php return [];');
        file_put_contents($this->configPath . '/database.php', '<?php return [];');
    }

    #[Subject]
    public function load(): void
    {
        $manager = new ConfigManager(
            envFilePath: $this->envFilePath,
            configPath: $this->configPath,
        );
        $manager->load();
    }
}
