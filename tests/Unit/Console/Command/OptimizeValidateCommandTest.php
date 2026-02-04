<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Console\Command\OptimizeValidateCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\Container;
use Pulsar\Core\Kernel;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function scandir;
use function sodium_bin2hex;

#[CoversClass(OptimizeValidateCommand::class)]
final class OptimizeValidateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_opt_val_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsOptimizeValidate(): void
    {
        $command = new OptimizeValidateCommand(new Kernel());

        self::assertSame('optimize:validate', $command->name);
    }

    #[Test]
    public function descriptionMentionsCI(): void
    {
        $command = new OptimizeValidateCommand(new Kernel());

        self::assertStringContainsString('CI', $command->description);
    }

    #[Test]
    public function hasStrictAndEncryptOptions(): void
    {
        $command = new OptimizeValidateCommand(new Kernel());

        self::assertArrayHasKey('strict', $command->options);
        self::assertArrayHasKey('encrypt', $command->options);
    }

    #[Test]
    public function nullCacheReturnsError(): void
    {
        $command = new OptimizeValidateCommand(new Kernel());
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize:validate'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $output->errorBuffer);
    }

    #[Test]
    public function successfulValidation(): void
    {
        $this->prepareProjectDirs();

        $configManager = new ConfigManager($this->tempDir . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $router->get('/test', self::class, 'test.index');
        $container->instance(Router::class, $router);

        $kernel = new Kernel($container, $router, null, $configManager);
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey);

        $command = new OptimizeValidateCommand($kernel, $cache);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize:validate'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Manifest: OK', $output->buffer);
        self::assertStringContainsString('Cache validation passed', $output->buffer);
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
