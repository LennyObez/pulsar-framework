<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\ContainerCache;

use function strlen;

#[CoversClass(ContainerCache::class)]
final class ContainerCacheTest extends TestCase
{
    private string $hmacKey;
    private CacheIntegrity $integrity;
    private ContainerCache $containerCache;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity($this->hmacKey);
        $this->containerCache = new ContainerCache($this->integrity);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_container_cache_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function writeAndLoadRoundTrip(): void
    {
        $hints = [
            'App\\Services\\UserService' => [
                ['name' => 'repository', 'type' => 'App\\Repositories\\UserRepository'],
                ['name' => 'logger', 'type' => 'Psr\\Log\\LoggerInterface'],
            ],
            'App\\Services\\OrderService' => [
                ['name' => 'userService', 'type' => 'App\\Services\\UserService'],
                ['name' => 'paymentGateway', 'type' => 'App\\Contracts\\PaymentGateway'],
            ],
        ];

        /** @phpstan-ignore argument.type */
        $this->containerCache->write($this->tempDir, $hints, false);

        $loaded = $this->containerCache->load($this->tempDir, []);

        self::assertNotNull($loaded);
        self::assertSame($hints, $loaded);
    }

    #[Test]
    public function loadReturnsNullForSchemaVersionMismatch(): void
    {
        // Write valid cache data
        $hints = [
            'App\\Services\\TestService' => [
                ['name' => 'dep', 'type' => 'App\\Contracts\\Dependency'],
            ],
        ];
        /** @phpstan-ignore argument.type */
        $this->containerCache->write($this->tempDir, $hints, false);

        // Tamper with the file to change the schema version
        $path = $this->tempDir . DIRECTORY_SEPARATOR . ContainerCache::FILENAME;
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        // The outer envelope wraps the inner serialized data.
        // We need to rewrite the file with a wrong schema.
        // To do this, directly write a new envelope with wrong schema.
        $wrongSchemaData = serialize([
            'schema' => 999,
            'hints' => $hints,
        ]);
        $this->integrity->writeEnvelope($path, $wrongSchemaData, false);

        $loaded = $this->containerCache->load($this->tempDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function loadReturnsNullForCorruptedData(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . ContainerCache::FILENAME;

        // Write garbage data
        file_put_contents($path, 'completely corrupted binary data here');

        $loaded = $this->containerCache->load($this->tempDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function loadReturnsNullForMissingFile(): void
    {
        $emptyDir = $this->tempDir . DIRECTORY_SEPARATOR . 'empty';
        mkdir($emptyDir, 0o750, true);

        $loaded = $this->containerCache->load($emptyDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function writeAndLoadWithEmptyHints(): void
    {
        $hints = [];

        $this->containerCache->write($this->tempDir, $hints, false);

        // The container returns hints from key 'hints'; if empty, still an array
        $loaded = $this->containerCache->load($this->tempDir, []);

        // Empty hints array should round-trip correctly
        self::assertNotNull($loaded);
        self::assertSame([], $loaded);
    }

    #[Test]
    public function loadReturnsNullForTamperedPayload(): void
    {
        $hints = [
            'App\\Services\\TestService' => [
                ['name' => 'dep', 'type' => 'App\\Contracts\\Dependency'],
            ],
        ];

        /** @phpstan-ignore argument.type */
        $this->containerCache->write($this->tempDir, $hints, false);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . ContainerCache::FILENAME;
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        // Tamper with the file by modifying bytes in the middle
        $tampered = $raw;
        $midpoint = (int) (strlen($tampered) / 2);
        $tampered[$midpoint] = $tampered[$midpoint] === 'X' ? 'Y' : 'X';
        file_put_contents($path, $tampered);

        $loaded = $this->containerCache->load($this->tempDir, []);

        self::assertNull($loaded);
    }

    #[Test]
    public function schemaVersionConstant(): void
    {
        self::assertSame(1, ContainerCache::SCHEMA_VERSION);
    }

    #[Test]
    public function filenameConstant(): void
    {
        self::assertSame('container.cache.bin', ContainerCache::FILENAME);
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
