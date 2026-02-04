<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function file_put_contents;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Console\Command\OptimizeClearCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(OptimizeClearCommand::class)]
final class OptimizeClearCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_optimize_clear_cmd_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsOptimizeClear(): void
    {
        $command = $this->createCommand();

        self::assertSame('optimize:clear', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = $this->createCommand();

        self::assertNotEmpty($command->description);
        self::assertStringContainsString('Clear', $command->description);
    }

    #[Test]
    public function nullCacheReturnsErrorWithHelpfulMessage(): void
    {
        $command = new OptimizeClearCommand();
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('optimize:clear'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY', $output->errorBuffer);
        self::assertStringContainsString('key:generate', $output->errorBuffer);
    }

    #[Test]
    public function acceptsNullCache(): void
    {
        $command = new OptimizeClearCommand();

        self::assertSame('optimize:clear', $command->name);
    }

    #[Test]
    public function successPathClearsCacheAndReturnsSuccess(): void
    {
        $command = $this->createCommand();
        $output = new BufferedOutput();

        // Create the cache directory with a dummy file
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $cache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());
        $cachePath = $cache->cachePath();
        mkdir($cachePath, 0o750, true);
        file_put_contents($cachePath . DIRECTORY_SEPARATOR . 'manifest.json', '{}');

        $command = new OptimizeClearCommand($cache);
        $exit = $command->execute(new ArrayInput('optimize:clear'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('cleared', $output->buffer);
        self::assertFileDoesNotExist($cachePath . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    private function createCommand(): OptimizeClearCommand
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $frameworkCache = new FrameworkCache($this->tempDir, $masterKey, new HmacService());

        return new OptimizeClearCommand($frameworkCache);
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
