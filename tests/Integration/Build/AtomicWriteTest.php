<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use function file_exists;
use function file_get_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Integration test: atomic write pattern — tmp + rename, no partial state.
 *
 * The build pipeline writes to .tmp files first, then renames to final paths.
 * If a build fails, no partial artifacts should remain. If it succeeds,
 * the final artifacts should be consistent.
 */
#[CoversClass(BuildCommand::class)]
final class AtomicWriteTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_atomic_test_' . bin2hex(random_bytes(8));
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
    public function successfulBuildLeavesNoTmpFiles(): void
    {
        $configManager = $this->createStub(ConfigManagerInterface::class);
        $configManager->method('configPath')->willReturn($this->configPath);
        $configManager->method('repository')->willReturn(new ConfigRepository());

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('configManager')->willReturn($configManager);
        $kernel->method('container')->willReturn($container);

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['runtime' => 'fpm']);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);

        // No .tmp files should remain
        $tmpFiles = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.tmp');
        self::assertSame([], $tmpFiles === false ? [] : $tmpFiles, 'No .tmp files should remain after successful build');
    }

    #[Test]
    public function buildManifestIsWrittenAtomically(): void
    {
        $configManager = $this->createStub(ConfigManagerInterface::class);
        $configManager->method('configPath')->willReturn($this->configPath);
        $configManager->method('repository')->willReturn(new ConfigRepository());

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('configManager')->willReturn($configManager);
        $kernel->method('container')->willReturn($container);

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['runtime' => 'fpm']);

        $command->execute($input, $output);

        $manifestPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json';
        self::assertTrue(file_exists($manifestPath), 'build-manifest.json should exist after build');

        $manifestJson = file_get_contents($manifestPath);
        self::assertNotFalse($manifestJson);

        // Manifest should be valid JSON that can be loaded
        $manifest = BuildManifest::fromJson($manifestJson);
        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
    }

    #[Test]
    public function buildMetadataIsWrittenAtomically(): void
    {
        $configManager = $this->createStub(ConfigManagerInterface::class);
        $configManager->method('configPath')->willReturn($this->configPath);
        $configManager->method('repository')->willReturn(new ConfigRepository());

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('configManager')->willReturn($configManager);
        $kernel->method('container')->willReturn($container);

        $command = new BuildCommand($kernel);
        $output = new BufferedOutput();
        $input = new ArrayInput('build', options: ['runtime' => 'fpm']);

        $command->execute($input, $output);

        $metadataPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'build-metadata.json';
        self::assertTrue(file_exists($metadataPath), 'build-metadata.json should exist after build');

        $metadataJson = file_get_contents($metadataPath);
        self::assertNotFalse($metadataJson);

        $metadata = json_decode($metadataJson, true);
        self::assertIsArray($metadata);
        self::assertArrayHasKey('builtAt', $metadata);
        self::assertArrayHasKey('phpVersion', $metadata);
        self::assertArrayHasKey('pulsarVersion', $metadata);
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
