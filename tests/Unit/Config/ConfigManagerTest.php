<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigOverrides;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Environment;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\Exception\MissingConfigException;
use Pulsar\Config\ObservabilityConfig;
use RuntimeException;

#[CoversClass(ConfigManager::class)]
final class ConfigManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_configmgr_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        // Clear env vars
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');
    }

    protected function tearDown(): void
    {
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');

        $this->cleanDir($this->tempDir);
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
                if (is_dir($path)) {
                    $this->cleanDir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }

    private function writeConfigFiles(): void
    {
        file_put_contents($this->tempDir . '/app.php', '<?php return [
            "name" => "TestApp",
            "env" => "local",
            "debug" => true,
            "timezone" => "UTC",
            "locale" => "en",
        ];');

        file_put_contents($this->tempDir . '/observability.php', '<?php return [
            "logging" => [
                "default_channel" => "file",
                "level" => "debug",
                "channels" => [
                    "file" => ["driver" => "file", "path" => "var/logs/test.log"],
                    "stderr" => ["driver" => "stream", "stream" => "php://stderr"],
                ],
            ],
            "audit" => [
                "enabled" => true,
                "log_path" => "var/logs/audit.jsonl",
                "events" => ["authentication"],
            ],
        ];');

        file_put_contents($this->tempDir . '/security.php', '<?php return [
            "session" => [
                "cookie_name" => "TEST_SESSION",
                "lifetime" => 3600,
                "cookie_httponly" => true,
                "cookie_secure" => true,
                "cookie_samesite" => "Strict",
                "regenerate_on_privilege_change" => true,
            ],
            "csrf" => [
                "enabled" => true,
                "token_length" => 32,
                "header_name" => "X-CSRF-Token",
                "form_field_name" => "_csrf_token",
            ],
            "headers" => [
                "X-Content-Type-Options" => "nosniff",
                "X-Frame-Options" => "DENY",
            ],
            "rate_limiting" => [
                "enabled" => true,
                "default_limit" => 60,
                "default_window" => 60,
            ],
        ];');
    }

    #[Test]
    public function loadsConfigFilesInOrder(): void
    {
        $this->writeConfigFiles();

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->load();

        $repo = $manager->repository();
        self::assertTrue($repo->has(AppConfig::class));
        self::assertTrue($repo->has(ObservabilityConfig::class));

        $appConfig = $repo->get(AppConfig::class);
        self::assertSame('TestApp', $appConfig->name);
    }

    #[Test]
    public function envOverridesFileValues(): void
    {
        $this->writeConfigFiles();

        putenv('APP_NAME=EnvApp');
        putenv('LOG_LEVEL=error');

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->load();

        $appConfig = $manager->repository()->get(AppConfig::class);
        self::assertSame('EnvApp', $appConfig->name);

        $obsConfig = $manager->repository()->get(ObservabilityConfig::class);
        self::assertSame('error', $obsConfig->loggingLevel);
    }

    #[Test]
    public function overridesAppliedLast(): void
    {
        $this->writeConfigFiles();

        $overrides = new ConfigOverrides();
        $overrides->add('app', ['name' => 'OverriddenApp']);

        $manager = new ConfigManager(
            configPath: $this->tempDir,
            overrides: $overrides,
        );
        $manager->load();

        $appConfig = $manager->repository()->get(AppConfig::class);
        self::assertSame('OverriddenApp', $appConfig->name);
    }

    #[Test]
    public function throwsForMissingConfigFile(): void
    {
        // Directory exists but no files — validation catches this before loadConfigFile
        $manager = new ConfigManager(configPath: $this->tempDir);

        $this->expectException(MissingConfigException::class);
        $manager->load();
    }

    #[Test]
    public function throwsForNonArrayReturn(): void
    {
        file_put_contents($this->tempDir . '/app.php', '<?php return "not an array";');
        file_put_contents($this->tempDir . '/observability.php', '<?php return [];');
        file_put_contents($this->tempDir . '/security.php', '<?php return [];');

        $manager = new ConfigManager(configPath: $this->tempDir);

        $this->expectException(ConfigException::class);
        $manager->load();
    }

    #[Test]
    public function repositoryAccessibleAfterLoad(): void
    {
        $this->writeConfigFiles();

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->load();

        $repo = $manager->repository();
        $env = $manager->environment();

        self::assertInstanceOf(AppConfig::class, $repo->get(AppConfig::class));
        self::assertInstanceOf(ObservabilityConfig::class, $repo->get(ObservabilityConfig::class));
        self::assertInstanceOf(Environment::class, $env);
    }

    #[Test]
    public function throwsWhenAccessingRepositoryBeforeLoad(): void
    {
        $manager = new ConfigManager(configPath: $this->tempDir);

        $this->expectException(ConfigException::class);
        $manager->repository();
    }

    #[Test]
    public function throwsWhenAccessingEnvironmentBeforeLoad(): void
    {
        $manager = new ConfigManager(configPath: $this->tempDir);

        $this->expectException(ConfigException::class);
        $manager->environment();
    }

    #[Test]
    public function worksWithNullConfigPath(): void
    {
        $manager = new ConfigManager();
        $manager->load();

        // Should work with empty defaults
        $repo = $manager->repository();
        self::assertTrue($repo->has(AppConfig::class));
    }

    #[Test]
    public function loadsEnvFile(): void
    {
        $this->writeConfigFiles();

        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "APP_NAME=FromEnvFile\n");

        $manager = new ConfigManager(
            configPath: $this->tempDir,
            envFilePath: $envFile,
        );
        $manager->load();

        $appConfig = $manager->repository()->get(AppConfig::class);
        self::assertSame('FromEnvFile', $appConfig->name);
    }

    /**
     * F4.10: extension-registered loaders run during `load()`
     * AFTER the framework's hardcoded sections. The DTO they
     * produce lands in the repository keyed by its class-string
     * — extensions resolve it via `repository()->get(...)`.
     */
    #[Test]
    public function extensionLoaderProducesConfigInRepository(): void
    {
        $this->writeConfigFiles();
        file_put_contents(
            $this->tempDir . '/myext.php',
            "<?php return ['enabled' => true, 'rate' => 42];",
        );

        $loader = new class implements \Pulsar\Config\ConfigLoaderInterface {
            public function configClass(): string
            {
                return ExtensionConfigStub::class;
            }

            public function load(array $data, Environment $environment, ConfigRepository $repository): object
            {
                /** @var bool $enabled */
                $enabled = $data['enabled'] ?? false;
                /** @var int $rate */
                $rate = $data['rate'] ?? 0;
                return new ExtensionConfigStub($enabled, $rate);
            }
        };

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->registerLoader('myext', $loader, optional: false);
        $manager->load();

        $resolved = $manager->repository()->get(ExtensionConfigStub::class);
        self::assertSame(true, $resolved->enabled);
        self::assertSame(42, $resolved->rate);
    }

    /**
     * F4.10: an optional loader whose config file is absent
     * simply skips — no exception, no entry in the repository.
     * A required loader (`optional: false`) throws when the
     * file is missing.
     */
    #[Test]
    public function optionalExtensionLoaderSkipsWhenFileMissing(): void
    {
        $this->writeConfigFiles();

        $loader = new class implements \Pulsar\Config\ConfigLoaderInterface {
            public function configClass(): string
            {
                return ExtensionConfigStub::class;
            }

            public function load(array $data, Environment $environment, ConfigRepository $repository): object
            {
                throw new RuntimeException('factory should not be called when file is missing');
            }
        };

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->registerLoader('absent', $loader, optional: true);
        $manager->load();

        self::assertFalse($manager->repository()->has(ExtensionConfigStub::class));
    }

    #[Test]
    public function requiredExtensionLoaderThrowsWhenFileMissing(): void
    {
        $this->writeConfigFiles();

        $loader = new class implements \Pulsar\Config\ConfigLoaderInterface {
            public function configClass(): string
            {
                return ExtensionConfigStub::class;
            }

            public function load(array $data, Environment $environment, ConfigRepository $repository): object
            {
                return new ExtensionConfigStub(false, 0);
            }
        };

        $manager = new ConfigManager(configPath: $this->tempDir);
        $manager->registerLoader('absent', $loader, optional: false);

        $this->expectException(MissingConfigException::class);
        $manager->load();
    }
}

/**
 * @internal stub DTO used by F4.10 extension-loader regression tests.
 */
final readonly class ExtensionConfigStub
{
    public function __construct(
        public bool $enabled,
        public int $rate,
    ) {}
}
