<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Console\Command\OptimizeCommand;
use Pulsar\Core\Kernel;
use Pulsar\Security\Crypto\MasterKey;

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

    private function createCommand(): OptimizeCommand
    {
        $kernel = new Kernel();
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $frameworkCache = new FrameworkCache($this->tempDir, $masterKey);

        return new OptimizeCommand($kernel, $frameworkCache);
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
