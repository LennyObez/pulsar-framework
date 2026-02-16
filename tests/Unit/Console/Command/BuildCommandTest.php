<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\BuildManifest;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command\BuildCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function strlen;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(BuildCommand::class)]
final class BuildCommandTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_buildcmd_test_' . bin2hex(random_bytes(8));
        $this->configPath = $this->tempDir . DIRECTORY_SEPARATOR . 'config';
        $this->cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';

        mkdir($this->configPath, 0o750, true);
        mkdir($this->cacheDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function commandIsConfiguredCorrectly(): void
    {
        $kernel = $this->createKernelStub();
        $command = new BuildCommand($kernel);

        self::assertSame('build', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function verifyModePassesWithValidArtifacts(): void
    {
        $kernel = $this->createKernelStub();

        // Write a valid artifact
        $content = '<?php return ["compiled" => true];';
        $contentHash = hash('sha256', $content);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $content);

        // Write a valid manifest
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', $contentHash, strlen($content)),
            ],
            contentHashes: [],
        );

        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            $manifest->toJson(),
        );

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['verify' => true]);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('verified successfully', $output->buffer);
    }

    #[Test]
    public function verifyModeFailsWithTamperedArtifact(): void
    {
        $kernel = $this->createKernelStub();

        // Write a tampered artifact (hash won't match)
        $originalContent = '<?php return ["original" => true];';
        $originalHash = hash('sha256', $originalContent);
        $tamperedContent = '<?php return ["tampered" => true];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $tamperedContent);

        // Manifest references original hash
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', $originalHash, strlen($originalContent)),
            ],
            contentHashes: [],
        );

        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            $manifest->toJson(),
        );

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['verify' => true]);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('verification failed', $output->errorBuffer);
    }

    #[Test]
    public function verifyModeFailsWithNoManifest(): void
    {
        $kernel = $this->createKernelStub();

        // No manifest file exists

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['verify' => true]);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('No build manifest found', $output->errorBuffer);
    }

    #[Test]
    public function compiledConfigIncludesAllowedClassesFalse(): void
    {
        $repository = new ConfigRepository();
        $configManager = $this->createStub(ConfigManagerInterface::class);
        $configManager->method('configPath')->willReturn($this->configPath);
        $configManager->method('repository')->willReturn($repository);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('configManager')->willReturn($configManager);
        $kernel->method('container')->willReturn($container);

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build');

        $command->execute($input, $output);

        $compiledPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php';
        self::assertFileExists($compiledPath);

        $content = file_get_contents($compiledPath);
        self::assertIsString($content);
        self::assertStringContainsString("'allowed_classes' => false", $content);
    }

    private function createKernelStub(): KernelInterface
    {
        $configManager = $this->createStub(ConfigManagerInterface::class);
        $configManager->method('configPath')->willReturn($this->configPath);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('configManager')->willReturn($configManager);
        $kernel->method('container')->willReturn($container);

        return $kernel;
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
