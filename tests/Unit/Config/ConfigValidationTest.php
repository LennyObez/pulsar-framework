<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\MissingConfigException;

#[CoversClass(ConfigManager::class)]
#[CoversClass(MissingConfigException::class)]
final class ConfigValidationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_config_validation_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    // ── Passing validation ─────────────────────────────────────────────

    #[Test]
    public function validationPassesWhenAllRequiredConfigsExist(): void
    {
        // Arrange
        $this->writeMinimalConfig('app');
        $this->writeMinimalConfig('security');
        $this->writeMinimalConfig('observability');

        $manager = new ConfigManager($this->tempDir);

        // Act -- should not throw
        $manager->validateRequiredConfigs();

        // Assert -- verify the required configs constant matches what we created
        self::assertSame(['app', 'security', 'observability'], ConfigManager::REQUIRED_CONFIGS);
    }

    #[Test]
    public function validationPassesWhenConfigPathIsNull(): void
    {
        // Arrange -- no config path
        $manager = new ConfigManager(null);

        // Act + Assert -- configPath() returns null, validation is a no-op
        $manager->validateRequiredConfigs();
        self::assertNull($manager->configPath());
    }

    // ── Failing validation ─────────────────────────────────────────────

    #[Test]
    public function validationThrowsWhenAppConfigIsMissing(): void
    {
        // Arrange
        $this->writeMinimalConfig('security');
        $this->writeMinimalConfig('observability');

        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('config/app.php');
        $this->expectExceptionMessage('pulsar new:config');

        // Act
        $manager->validateRequiredConfigs();
    }

    #[Test]
    public function validationThrowsWhenSecurityConfigIsMissing(): void
    {
        // Arrange
        $this->writeMinimalConfig('app');
        $this->writeMinimalConfig('observability');

        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('config/security.php');

        // Act
        $manager->validateRequiredConfigs();
    }

    #[Test]
    public function validationThrowsWhenObservabilityConfigIsMissing(): void
    {
        // Arrange
        $this->writeMinimalConfig('app');
        $this->writeMinimalConfig('security');

        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('config/observability.php');

        // Act
        $manager->validateRequiredConfigs();
    }

    #[Test]
    public function validationThrowsWhenMultipleConfigsMissing(): void
    {
        // Arrange -- empty config dir, no files at all
        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('config/app.php');
        $this->expectExceptionMessage('config/security.php');
        $this->expectExceptionMessage('config/observability.php');

        // Act
        $manager->validateRequiredConfigs();
    }

    #[Test]
    public function validationAcceptsCustomRequiredList(): void
    {
        // Arrange -- only create 'custom-a'
        $this->writeMinimalConfig('custom-a');

        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('config/custom-b.php');

        // Act
        $manager->validateRequiredConfigs(['custom-a', 'custom-b']);
    }

    #[Test]
    public function customRequiredListPassesWhenAllPresent(): void
    {
        // Arrange
        $this->writeMinimalConfig('alpha');
        $this->writeMinimalConfig('beta');

        $manager = new ConfigManager($this->tempDir);

        // Act
        $manager->validateRequiredConfigs(['alpha', 'beta']);

        // Assert -- reached without exception; verify configs exist on disk
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'alpha.php');
        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'beta.php');
    }

    // ── Integration: load() triggers validation ────────────────────────

    #[Test]
    public function loadThrowsMissingConfigExceptionWhenRequiredFileMissing(): void
    {
        // Arrange -- missing all required configs
        $manager = new ConfigManager($this->tempDir);

        // Assert
        $this->expectException(MissingConfigException::class);

        // Act
        $manager->load();
    }

    // ── MissingConfigException message format ──────────────────────────

    #[Test]
    public function singleFileMissingProducesSpecificMessage(): void
    {
        // Act
        $exception = MissingConfigException::forFile('security');

        // Assert
        self::assertStringContainsString('config/security.php', $exception->getMessage());
        self::assertStringContainsString('pulsar new:config security', $exception->getMessage());
    }

    #[Test]
    public function multipleFilesMissingListsAllInMessage(): void
    {
        // Act
        $exception = MissingConfigException::forFiles(['app', 'security', 'observability']);

        // Assert
        self::assertStringContainsString('config/app.php', $exception->getMessage());
        self::assertStringContainsString('config/security.php', $exception->getMessage());
        self::assertStringContainsString('config/observability.php', $exception->getMessage());
    }

    #[Test]
    public function singleItemArrayDelegatesToForFile(): void
    {
        // Act
        $exception = MissingConfigException::forFiles(['app']);

        // Assert -- should produce single-file format
        self::assertStringContainsString('pulsar new:config app', $exception->getMessage());
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function writeMinimalConfig(string $name): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . $name . '.php',
            '<?php return [];',
        );
    }

    /**
     * Remove a directory tree that lives strictly under sys_get_temp_dir().
     */
    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $realDir = realpath($dir);
        $realTemp = realpath(sys_get_temp_dir());

        if ($realDir === false || $realTemp === false || !str_starts_with($realDir, $realTemp)) {
            return;
        }

        $items = scandir($realDir);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $child = $realDir . DIRECTORY_SEPARATOR . $item;

                if (is_dir($child)) {
                    $this->cleanDir($child);
                } else {
                    $this->safeUnlink($child, $realTemp);
                }
            }
        }

        rmdir($realDir);
    }

    /**
     * Delete a file only if its real path is under the given safe root.
     */
    private function safeUnlink(string $file, string $safeRoot): void
    {
        $resolved = realpath($file);

        if ($resolved !== false && str_starts_with($resolved, $safeRoot)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            unlink($resolved);
        }
    }
}
