<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\UnknownKeyReporter;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\DocumentationWiring;
use Pulsar\Core\Wiring\EdgeWiring;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\Core\Wiring\IntrospectionWiring;
use Pulsar\Core\Wiring\ProfilerWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Stringable;

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
 * Behavioral proof that a section built by its own wiring — outside the
 * {@see ConfigManager} sweep — still surfaces an unrecognized key at boot.
 *
 * Each test runs the wiring's real `wire()` method against a config file carrying
 * a bogus key and asserts the warning is logged, so the guarantee is the same as
 * for a ConfigManager-owned section: a typo is never silently dropped. Exercising
 * the actual wiring, not a stand-in, is the point — the earlier gap existed
 * precisely because nothing ran this path with a bad key.
 */
#[CoversClass(EdgeWiring::class)]
#[CoversClass(DocumentationWiring::class)]
#[CoversClass(IntrospectionWiring::class)]
#[CoversClass(AntiSpamWiring::class)]
#[CoversClass(ProfilerWiring::class)]
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
    public function edgeWiringSurfacesAnUnknownKeyAtBoot(): void
    {
        $this->writeConfig('edge.php', "'enabled' => true, 'geo_redirect' => []");

        $spy = new WarningSpy();
        $container = new Container();
        $container->instance(LoggerInterface::class, $spy);

        new EdgeWiring()->wire(
            $container,
            new ConfigManager(configPath: $this->tempDir),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertTrue(
            $spy->has('config section "edge": unrecognized key "geo_redirect"'),
            'a typo in config/edge.php must surface at boot; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function documentationWiringSurfacesAnUnknownKeyAtBoot(): void
    {
        $this->writeConfig('documentation.php', "'enabled' => true, 'verisons' => []");

        $spy = new WarningSpy();
        $container = new Container();
        $container->instance(LoggerInterface::class, $spy);

        new DocumentationWiring()->wire(
            $container,
            new ConfigManager(configPath: $this->tempDir),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertTrue(
            $spy->has('config section "documentation": unrecognized key "verisons"'),
            'a typo in config/documentation.php must surface at boot; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function introspectionWiringSurfacesAnUnknownKeyAtBoot(): void
    {
        // IntrospectionWiring pulls AppConfig and the environment from a loaded
        // ConfigManager, so this section needs the manager loaded, not merely a
        // config path — app.php is the minimum for that.
        $this->writeConfig('app.php', "'name' => 'T', 'env' => 'local'");
        $this->writeConfig('security.php', "'session' => ['cookie_name' => 'T']");
        $this->writeConfig('observability.php', "'logging' => ['default_channel' => 'stderr', 'channels' => ['stderr' => ['driver' => 'stream', 'stream' => 'php://stderr']]]");
        $this->writeConfig('introspection.php', "'enabled' => true, 'enabledd' => false");

        $configManager = new ConfigManager(configPath: $this->tempDir);
        $configManager->load();

        $spy = new WarningSpy();
        $container = new Container();
        $container->instance(LoggerInterface::class, $spy);

        new IntrospectionWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertTrue(
            $spy->has('config section "introspection": unrecognized key "enabledd"'),
            'a typo in config/introspection.php must surface at boot; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function antiSpamWiringSurfacesAnUnknownKeyAtBoot(): void
    {
        // anti-spam has the separator a class name would lose (anti-spam, not
        // antispam), and its keys are bot defences — a typo silently reverts one.
        $this->writeConfig('anti-spam.php', "'honeypot_enabled' => true, 'captcha_enabledd' => true");

        $spy = new WarningSpy();
        $container = new Container();
        $container->instance(LoggerInterface::class, $spy);

        new AntiSpamWiring()->wire(
            $container,
            new ConfigManager(configPath: $this->tempDir),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertTrue(
            $spy->has('config section "anti-spam": unrecognized key "captcha_enabledd"'),
            'a typo in config/anti-spam.php must surface at boot; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function profilerWiringSurfacesAnUnknownKeyAtBoot(): void
    {
        $this->writeConfig('profiler.php', "'enabled' => true, 'max_entrees' => 10");

        $spy = new WarningSpy();
        $container = new Container();
        $container->instance(LoggerInterface::class, $spy);

        new ProfilerWiring()->wire(
            $container,
            new ConfigManager(configPath: $this->tempDir),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertTrue(
            $spy->has('config section "profiler": unrecognized key "max_entrees"'),
            'a typo in config/profiler.php must surface at boot; got: ' . $spy->dump(),
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
