<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\UnknownKeyReporter;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\DocumentationWiring;
use Pulsar\Core\Wiring\EdgeWiring;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\Core\Wiring\IntrospectionWiring;
use Pulsar\Core\Wiring\ProfilerWiring;
use Pulsar\Core\Wiring\ProvidesConfigLoaders;
use Pulsar\Core\Wiring\SecurityWiring;
use Stringable;

use function array_filter;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Behavioral proof that an unrecognized config key surfaces at boot, whichever
 * mechanism owns the section.
 *
 * Sections still read ad-hoc by their wiring (documentation, introspection,
 * anti-spam, profiler) surface the typo through the wiring's own reporter; a
 * section repatriated to the {@see \Pulsar\Config\ConfigRepository} (edge)
 * surfaces it through {@see ConfigManager}'s central post-load sweep. Same
 * guarantee either way: a typo is never silently dropped. Exercising the real
 * path, not a stand-in, is the point — the earlier gap existed precisely because
 * nothing ran these paths with a bad key.
 */
#[CoversClass(EdgeWiring::class)]
#[CoversClass(DocumentationWiring::class)]
#[CoversClass(IntrospectionWiring::class)]
#[CoversClass(AntiSpamWiring::class)]
#[CoversClass(ProfilerWiring::class)]
#[CoversClass(SecurityWiring::class)]
#[CoversClass(ConfigManager::class)]
#[CoversClass(UnknownKeyReporter::class)]
#[CoversClass(ReportsConfigKeys::class)]
final class WiringConfigKeyReportingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_wiring_keys_' . uniqid();
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    #[Test]
    public function edgeUnknownKeySurfacesViaTheCentralSweepAtLoad(): void
    {
        // Repatriated sections (edge, documentation, profiler) are built into the
        // ConfigRepository by their loader, so unknown keys surface through
        // ConfigManager's central post-load sweep — the same chokepoint as every
        // repository-owned section — not a per-wiring report. Same guarantee
        // (a typo is never silently dropped), one mechanism.
        $configManager = $this->loadThroughRepository(new EdgeWiring(), 'edge.php', "'enabled' => true, 'geo_redirect' => []");
        $this->assertSweepSurfaces($configManager, 'config section "edge": unrecognized key "geo_redirect"');
    }

    #[Test]
    public function documentationUnknownKeySurfacesViaTheCentralSweepAtLoad(): void
    {
        $configManager = $this->loadThroughRepository(new DocumentationWiring(), 'documentation.php', "'enabled' => true, 'verisons' => []");
        $this->assertSweepSurfaces($configManager, 'config section "documentation": unrecognized key "verisons"');
    }

    #[Test]
    public function introspectionUnknownKeySurfacesViaTheCentralSweepAtLoad(): void
    {
        // IntrospectionConfig's default-enabled derives from AppConfig's resolved
        // EnvironmentMode; its loader reads that from the repository at load, and
        // unknown keys surface through the central sweep.
        $configManager = $this->loadThroughRepository(new IntrospectionWiring(), 'introspection.php', "'enabled' => true, 'enabledd' => false");
        $this->assertSweepSurfaces($configManager, 'config section "introspection": unrecognized key "enabledd"');
    }

    #[Test]
    public function antiSpamUnknownKeySurfacesWithTheRealHyphenatedBasename(): void
    {
        // anti-spam has the separator a class name would lose (AntiSpamConfig ->
        // "antispam"); ConfigManager records the loader basename so the label is
        // the real file, "anti-spam". Its keys are bot defences — a typo silently
        // reverts one, so it must surface. The whole file loads through one
        // AntiSpamConfigSet; unknown keys delegate to the top-level AntiSpamConfig.
        $configManager = $this->loadThroughRepository(new AntiSpamWiring(), 'anti-spam.php', "'honeypot_enabled' => true, 'captcha_enabledd' => true");
        $this->assertSweepSurfaces($configManager, 'config section "anti-spam": unrecognized key "captcha_enabledd"');
    }

    #[Test]
    public function profilerUnknownKeySurfacesViaTheCentralSweepAtLoad(): void
    {
        $configManager = $this->loadThroughRepository(new ProfilerWiring(), 'profiler.php', "'enabled' => true, 'max_entrees' => 10");
        $this->assertSweepSurfaces($configManager, 'config section "profiler": unrecognized key "max_entrees"');
    }

    #[Test]
    public function dataProtectionUnknownKeyUsesTheRealFileBasenameNotTheClassName(): void
    {
        // Regression: DataProtectionConfig's class name lowercases to
        // "dataprotection", losing the underscore. The central sweep must label
        // an unknown key against the REAL file — config/data_protection.php — so an
        // operator debugging a GDPR retention typo is not misdirected to a
        // nonexistent config/dataprotection.php. ConfigManager records the loader
        // basename per DTO class for exactly this.
        $configManager = $this->loadThroughRepository(new SecurityWiring(), 'data_protection.php', "'retention' => [], 'retenton' => []");
        $this->assertSweepSurfaces($configManager, 'config section "data_protection": unrecognized key "retenton"');
    }

    /**
     * Load a repository-owned section through its wiring's loader exactly as
     * Kernel does at boot (register loaders, then load), so the central post-load
     * unknown-key sweep has run. The three mandatory config files are stubbed
     * because load() requires them.
     */
    private function loadThroughRepository(ProvidesConfigLoaders $wiring, string $file, string $body): ConfigManager
    {
        $this->writeConfig('app.php', "'name' => 'T', 'env' => 'local'");
        $this->writeConfig('security.php', "'session' => ['cookie_name' => 'T']");
        $this->writeConfig('observability.php', "'logging' => ['default_channel' => 'stderr', 'channels' => ['stderr' => ['driver' => 'stream', 'stream' => 'php://stderr']]]");
        $this->writeConfig($file, $body);

        $configManager = new ConfigManager(configPath: $this->tempDir);
        ConfigLoaderRegistrar::register($configManager, [$wiring]);
        $configManager->load();

        return $configManager;
    }

    private function assertSweepSurfaces(ConfigManager $configManager, string $expected): void
    {
        $warnings = $configManager->unknownConfigKeyWarnings();
        $surfaced = array_filter($warnings, static fn(string $w): bool => str_contains($w, $expected));
        self::assertNotEmpty(
            $surfaced,
            $expected . ' must surface via the central sweep; got: ' . implode(' | ', $warnings),
        );
    }

    private function writeConfig(string $file, string $body): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $file, '<?php return [' . $body . '];');
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $this->cleanDir($path) : unlink($path);
            }
        }

        rmdir($dir);
    }
}

/**
 * Minimal PSR-3 logger that records warning messages for assertion.
 */
final class WarningSpy extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }

    public function has(string $needle): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function dump(): string
    {
        return $this->warnings === [] ? '(no warnings)' : implode(' | ', $this->warnings);
    }
}
