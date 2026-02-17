<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\ConfigCache;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command\ConfigCacheCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Security\Crypto\HmacInterface;
use SodiumException;

use function bin2hex;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(ConfigCacheCommand::class)]
final class ConfigCacheCommandTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/pulsar_test_config_cache_' . bin2hex(random_bytes(4));
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0o755, true);
        }
    }

    #[Test]
    public function configuredWithCorrectName(): void
    {
        $config = new ConfigRepository();
        $cache = $this->createConfigCache();

        $command = new ConfigCacheCommand($config, $cache, $this->cachePath);

        self::assertSame('config:cache', $command->name);
        self::assertNotEmpty($command->description);
        self::assertArrayHasKey('encrypt', $command->options);
    }

    #[Test]
    public function successfulCacheReturnsSuccess(): void
    {
        $config = new ConfigRepository();
        $cache = $this->createConfigCache();

        $command = new ConfigCacheCommand($config, $cache, $this->cachePath);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('config:cache'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Configuration cached successfully', $output->buffer);
    }

    #[Test]
    public function cacheWriteToInvalidPathReturnsError(): void
    {
        $config = new ConfigRepository();

        // Create an integrity instance whose computeHex throws, simulating a crypto failure
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willThrowException(
            new SodiumException('HMAC key material invalid'),
        );

        $integrity = new CacheIntegrity($hmac, 'bad_key');
        $cache = new ConfigCache($integrity);

        $command = new ConfigCacheCommand($config, $cache, $this->cachePath);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('config:cache'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Failed to cache configuration', $output->errorBuffer);
    }

    private function createConfigCache(): ConfigCache
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willReturn('test_hmac_signature');
        $hmac->method('verifyHex')->willReturn(true);

        $integrity = new CacheIntegrity($hmac, 'test_hmac_key');

        return new ConfigCache($integrity);
    }
}
