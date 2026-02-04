<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command;

use function extension_loaded;
use function file_put_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\StudioDoctorCommand;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Config\StudioServerConfig;

use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(StudioDoctorCommand::class)]
final class StudioDoctorCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_studio_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $config = $this->createConfig();
        $command = new StudioDoctorCommand($config, $this->tmpDir);

        self::assertSame('studio:doctor', $command->name);
        self::assertSame('Run Studio diagnostic checks', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function displaysTextOutputWithAllChecksPassed(): void
    {
        // Create required directories and files
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(
            enabled: true,
            storagePath: 'storage/studio/studio.sqlite',
            port: 59999, // Use unlikely port to avoid collision
        );

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertStringContainsString('Studio Doctor', $output->buffer);
        self::assertStringContainsString('[+] Studio enabled', $output->buffer);
        self::assertStringContainsString('[+] PDO SQLite extension', $output->buffer);
        self::assertStringContainsString('[+] Storage directory writable', $output->buffer);
        self::assertStringContainsString('[+] Config file exists', $output->buffer);

        // Exit code depends on whether all checks pass (including port and optional sodium)
        self::assertContains($exit, [ExitCode::Success->value, ExitCode::Error->value]);
    }

    #[Test]
    public function displaysStudioDisabledCheck(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(enabled: false);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertStringContainsString('[-] Studio enabled', $output->buffer);
        self::assertStringContainsString('Studio is disabled', $output->buffer);
    }

    #[Test]
    public function displaysStorageDirectoryNotWritableCheck(): void
    {
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');
        // Don't create storage directory

        $config = $this->createConfig(storagePath: 'nonexistent/path/studio.sqlite');

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[-] Storage directory writable', $output->buffer);
        self::assertStringContainsString('not writable or does not exist', $output->buffer);
    }

    #[Test]
    public function displaysConfigFileNotFoundCheck(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        // Don't create config file

        $config = $this->createConfig();

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[-] Config file exists', $output->buffer);
        self::assertStringContainsString('config/studio.php not found', $output->buffer);
    }

    #[Test]
    public function displaysSuccessMessageWhenAllChecksPassed(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(enabled: true, port: 59998);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:doctor'), $output);

        // Check based on whether required checks passed
        if ($exit === ExitCode::Success->value) {
            self::assertStringContainsString('[SUCCESS] All checks passed.', $output->buffer);
        } else {
            self::assertStringContainsString('[WARNING] Some checks failed.', $output->buffer);
        }
    }

    #[Test]
    public function displaysWarningMessageWhenChecksFailed(): void
    {
        // Don't create required directories
        $config = $this->createConfig(enabled: false);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[WARNING] Some checks failed.', $output->buffer);
    }

    #[Test]
    public function outputsJsonFormat(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(enabled: true, port: 59997);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(
            new ArrayInput('studio:doctor', [], ['json' => true]),
            $output,
        );

        /** @var array{all_passed: bool, checks: list<array{name: string, passed: bool, message: string}>} $json */
        $json = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsBool($json['all_passed']);
        self::assertIsArray($json['checks']);
        self::assertNotEmpty($json['checks']);

        // Verify check structure
        $checkNames = array_column($json['checks'], 'name');
        self::assertContains('Studio enabled', $checkNames);
        self::assertContains('PDO SQLite extension', $checkNames);
        self::assertContains('Storage directory writable', $checkNames);
        self::assertContains('Config file exists', $checkNames);
        self::assertContains('Server port available', $checkNames);
        self::assertContains('Sodium extension (optional)', $checkNames);
    }

    #[Test]
    public function jsonOutputContainsCheckDetails(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(enabled: true);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(
            new ArrayInput('studio:doctor', [], ['json' => true]),
            $output,
        );

        /** @var array{all_passed: bool, checks: list<array{name: string, passed: bool, message: string}>} $json */
        $json = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        // Find Studio enabled check
        $enabledCheck = null;
        foreach ($json['checks'] as $check) {
            if ($check['name'] === 'Studio enabled') {
                $enabledCheck = $check;
                break;
            }
        }

        self::assertNotNull($enabledCheck);
        self::assertTrue($enabledCheck['passed']);
        self::assertSame('Studio is enabled', $enabledCheck['message']);
    }

    #[Test]
    public function checksPdoSqliteExtension(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig();

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('studio:doctor'), $output);

        // Check is based on actual extension status
        if (extension_loaded('pdo_sqlite')) {
            self::assertStringContainsString('[+] PDO SQLite extension', $output->buffer);
            self::assertStringContainsString('pdo_sqlite is loaded', $output->buffer);
        } else {
            self::assertStringContainsString('[-] PDO SQLite extension', $output->buffer);
            self::assertStringContainsString('pdo_sqlite extension is not loaded', $output->buffer);
        }
    }

    #[Test]
    public function checksSodiumExtension(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig();

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('studio:doctor'), $output);

        // Sodium is optional, so just check the output format
        if (extension_loaded('sodium')) {
            self::assertStringContainsString('[+] Sodium extension (optional)', $output->buffer);
            self::assertStringContainsString('encryption-at-rest available', $output->buffer);
        } else {
            self::assertStringContainsString('[-] Sodium extension (optional)', $output->buffer);
            self::assertStringContainsString('encryption-at-rest unavailable', $output->buffer);
        }
    }

    #[Test]
    public function checksServerPortAvailability(): void
    {
        mkdir($this->tmpDir . '/storage/studio', 0o777, true);
        mkdir($this->tmpDir . '/config', 0o777, true);
        file_put_contents($this->tmpDir . '/config/studio.php', '<?php return [];');

        $config = $this->createConfig(port: 59996);

        $command = new StudioDoctorCommand($config, $this->tmpDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('studio:doctor'), $output);

        self::assertStringContainsString('Server port available', $output->buffer);
        self::assertStringContainsString('59996', $output->buffer);
    }

    private function createConfig(
        bool $enabled = true,
        string $storagePath = 'storage/studio/studio.sqlite',
        string $host = '127.0.0.1',
        int $port = 8585,
    ): StudioConfig {
        return new StudioConfig(
            enabled: $enabled,
            storagePath: $storagePath,
            retention: new StudioRetentionConfig(),
            security: new StudioSecurityConfig(),
            server: new StudioServerConfig(host: $host, port: $port),
            collectors: new StudioCollectorConfig(),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
