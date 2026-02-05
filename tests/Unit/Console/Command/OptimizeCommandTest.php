<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Console\Command\OptimizeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(OptimizeCommand::class)]
final class OptimizeCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_optimize_cmd_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsOptimize(): void
    {
        $command = $this->createCommand();

        self::assertSame('optimize', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = $this->createCommand();

        self::assertNotEmpty($command->description);
        self::assertStringContainsString('Cache', $command->description);
    }

    #[Test]
    public function hasStrictOption(): void
    {
        $command = $this->createCommand();

        self::assertArrayHasKey('strict', $command->options);
    }

    #[Test]
    public function hasEncryptOption(): void
    {
        $command = $this->createCommand();

        self::assertArrayHasKey('encrypt', $command->options);
    }

    #[Test]
    public function nullCacheReturnsErrorWithHelpfulMessage(): void
    {
        $command = new OptimizeCommand(new Kernel());
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $output->errorBuffer);
        self::assertStringContainsString('key:generate', $output->errorBuffer);
    }

    #[Test]
    public function acceptsNullCache(): void
    {
        $command = new OptimizeCommand(new Kernel());

        self::assertSame('optimize', $command->name);
    }

    #[Test]
    public function noConfigReturnSuccessWithMessage(): void
    {
        $kernel = new Kernel();
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $command = new OptimizeCommand($kernel, $cache);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No configuration loaded', $output->buffer);
    }

    #[Test]
    public function successWithConfigAndRoutes(): void
    {
        $this->prepareProjectDirs();

        $configManager = new ConfigManager($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $router->get('/users', self::class, 'users.index');
        $container->instance(Router::class, $router);

        $kernel = new Kernel($container, $router, null, $configManager);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $command = new OptimizeCommand($kernel, $cache);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Config: cached', $output->buffer);
        self::assertStringContainsString('Routes: 1 cached', $output->buffer);
        self::assertStringContainsString('Container: cached', $output->buffer);
        self::assertStringContainsString('Framework optimized successfully', $output->buffer);
    }

    #[Test]
    public function strictModeRejectsClosureRoutes(): void
    {
        $this->prepareProjectDirs();

        $configManager = new ConfigManager($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $router->get('/api/data', static fn() => 'data', 'api.data');
        $container->instance(Router::class, $router);

        $kernel = new Kernel($container, $router, null, $configManager);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $command = new OptimizeCommand($kernel, $cache);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize', [], ['strict' => true]), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('closure-based route', $output->buffer);
        self::assertStringContainsString('/api/data', $output->buffer);
    }

    #[Test]
    public function successWithSkippedClosureRoutes(): void
    {
        $this->prepareProjectDirs();

        $configManager = new ConfigManager($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $router->get('/users', self::class, 'users.index');
        $router->get('/health', static fn() => 'ok', 'health');
        $container->instance(Router::class, $router);

        $kernel = new Kernel($container, $router, null, $configManager);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $command = new OptimizeCommand($kernel, $cache);
        $output = new BufferedOutput();

        // Without --strict, closure routes are skipped but not an error
        $exit = $command->execute(new ArrayInput('optimize'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('1 cached, 1 skipped', $output->buffer);
        self::assertStringContainsString('closure handler', $output->buffer);
    }

    #[Test]
    public function buildContainerHintsFromBindings(): void
    {
        $this->prepareProjectDirs();

        $configManager = new ConfigManager($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        $container = new Container();
        // Register a class binding so buildContainerHints exercises the reflection path
        $container->bind(Router::class, Router::class);
        $container->instance(Router::class, new Router());

        $kernel = new Kernel($container, null, null, $configManager);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $command = new OptimizeCommand($kernel, $cache);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Framework optimized successfully', $output->buffer);
    }

    private function createCommand(): OptimizeCommand
    {
        $kernel = new Kernel();
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $frameworkCache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());

        return new OptimizeCommand($kernel, $frameworkCache);
    }

    private function prepareProjectDirs(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'config', 0o750, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer', 0o750, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'src', 0o750, true);
        $configPath = $this->tempDir . DIRECTORY_SEPARATOR . 'config';
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'test', 'env' => 'testing', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'observability.php',
            "<?php\nreturn ['logging' => ['default_channel' => 'file', 'level' => 'info', 'channels' => []], 'metrics' => ['enabled' => false, 'exporters' => []], 'tracing' => ['enabled' => false, 'sampling_rate' => 0.0], 'error_tracking' => ['enabled' => false, 'max_groups' => 100, 'max_recent_events_per_group' => 5, 'sensitive_fields' => []], 'audit' => ['enabled' => false, 'log_path' => '/dev/null', 'events' => []]];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'security.php',
            "<?php\nreturn ['session' => ['cookie_name' => 'TEST', 'lifetime' => 3600, 'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'regenerate_on_privilege_change' => true], 'csrf' => ['enabled' => false, 'token_length' => 32, 'header_name' => 'X-CSRF-Token', 'form_field_name' => '_csrf'], 'headers' => [], 'rate_limiting' => ['enabled' => false, 'default_limit' => 60, 'default_window' => 60], 'auth' => ['default_guard' => 'session', 'guards' => [], 'two_factor' => ['enabled' => false, 'issuer' => 'Test', 'code_digits' => 6, 'code_period' => 30, 'verification_window' => 1, 'recovery_code_count' => 8], 'authorization' => ['roles' => [], 'super_roles' => []]]];\n",
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
