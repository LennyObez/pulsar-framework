<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheManifest;

#[CoversClass(CacheManifest::class)]
final class CacheManifestTest extends TestCase
{
    private string $hmacKey;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_manifest_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function writeAndLoadRoundTripWithValidKey(): void
    {
        $caches = [
            'config' => ['sha256' => str_repeat('a', 64), 'hmac' => str_repeat('b', 64)],
            'routes' => ['sha256' => str_repeat('c', 64), 'hmac' => str_repeat('d', 64)],
        ];

        $manifest = CacheManifest::write(
            cachePath: $this->tempDir,
            hmacKey: $this->hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0-rc.2',
            appEnv: 'production',
            invalidationKey: hash('sha256', 'test-invalidation'),
            allowedClassesHash: hash('sha256', '[]'),
            caches: $caches,
            strict: true,
            encrypted: false,
        );

        self::assertSame(1, $manifest->schemaVersion);
        self::assertSame('1.0.0-rc.2', $manifest->frameworkVersion);
        self::assertSame('production', $manifest->appEnv);
        self::assertTrue($manifest->strict);
        self::assertFalse($manifest->encrypted);

        $loaded = CacheManifest::load($this->tempDir, $this->hmacKey);

        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->schemaVersion);
        self::assertSame('1.0.0-rc.2', $loaded->frameworkVersion);
        self::assertSame('production', $loaded->appEnv);
        self::assertSame($manifest->invalidationKey, $loaded->invalidationKey);
        self::assertSame($manifest->allowedClassesHash, $loaded->allowedClassesHash);
        self::assertSame($caches, $loaded->caches);
        self::assertTrue($loaded->strict);
        self::assertFalse($loaded->encrypted);
    }

    #[Test]
    public function loadReturnsNullForMissingFile(): void
    {
        $nonExistentDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent';
        mkdir($nonExistentDir, 0o750, true);

        $result = CacheManifest::load($nonExistentDir, $this->hmacKey);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForTamperedManifest(): void
    {
        $_ = CacheManifest::write(
            cachePath: $this->tempDir,
            hmacKey: $this->hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0',
            appEnv: 'production',
            invalidationKey: hash('sha256', 'test'),
            allowedClassesHash: hash('sha256', '[]'),
            caches: [],
            strict: false,
            encrypted: false,
        );

        // Tamper with the manifest file
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $content = file_get_contents($manifestPath);
        self::assertIsString($content);

        $tampered = str_replace('production', 'tampered!', $content);
        file_put_contents($manifestPath, $tampered);

        $result = CacheManifest::load($this->tempDir, $this->hmacKey);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForWrongHmacKey(): void
    {
        $_ = CacheManifest::write(
            cachePath: $this->tempDir,
            hmacKey: $this->hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0',
            appEnv: 'production',
            invalidationKey: hash('sha256', 'test'),
            allowedClassesHash: hash('sha256', '[]'),
            caches: [],
            strict: false,
            encrypted: false,
        );

        $wrongKey = random_bytes(32);
        $result = CacheManifest::load($this->tempDir, $wrongKey);

        self::assertNull($result);
    }

    #[Test]
    public function canonicalizeProducesDeterministicOutputRegardlessOfKeyOrder(): void
    {
        $data1 = [
            'zebra' => 'z',
            'alpha' => 'a',
            'middle' => 'm',
        ];

        $data2 = [
            'alpha' => 'a',
            'middle' => 'm',
            'zebra' => 'z',
        ];

        $canonical1 = CacheManifest::canonicalize($data1);
        $canonical2 = CacheManifest::canonicalize($data2);

        self::assertSame($canonical1, $canonical2);
    }

    #[Test]
    public function canonicalizeRecursivelySortsNestedArrays(): void
    {
        $data1 = [
            'outer' => [
                'z_inner' => 'z',
                'a_inner' => [
                    'z_deep' => 3,
                    'a_deep' => 1,
                    'm_deep' => 2,
                ],
            ],
            'first' => 'value',
        ];

        $data2 = [
            'first' => 'value',
            'outer' => [
                'a_inner' => [
                    'a_deep' => 1,
                    'm_deep' => 2,
                    'z_deep' => 3,
                ],
                'z_inner' => 'z',
            ],
        ];

        $canonical1 = CacheManifest::canonicalize($data1);
        $canonical2 = CacheManifest::canonicalize($data2);

        self::assertSame($canonical1, $canonical2);
    }

    #[Test]
    public function canonicalizePreservesListOrder(): void
    {
        $data1 = ['items' => [3, 1, 2]];
        $data2 = ['items' => [3, 1, 2]];

        $canonical1 = CacheManifest::canonicalize($data1);
        $canonical2 = CacheManifest::canonicalize($data2);

        self::assertSame($canonical1, $canonical2);

        // Different list order should produce different output
        $data3 = ['items' => [1, 2, 3]];
        $canonical3 = CacheManifest::canonicalize($data3);

        self::assertNotSame($canonical1, $canonical3);
    }

    #[Test]
    public function manifestHmacUsesConstantTimeComparison(): void
    {
        $_ = CacheManifest::write(
            cachePath: $this->tempDir,
            hmacKey: $this->hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0',
            appEnv: 'production',
            invalidationKey: hash('sha256', 'test'),
            allowedClassesHash: hash('sha256', '[]'),
            caches: [],
            strict: false,
            encrypted: false,
        );

        // Tamper the HMAC in the manifest to a similar but different value
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $content = file_get_contents($manifestPath);
        self::assertIsString($content);

        $data = json_decode($content, true);
        self::assertIsArray($data);

        $originalHmac = $data['manifest_hmac'];
        self::assertIsString($originalHmac);

        // Change last character
        $data['manifest_hmac'] = substr($originalHmac, 0, -1) . ($originalHmac[-1] === 'a' ? 'b' : 'a');
        file_put_contents($manifestPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = CacheManifest::load($this->tempDir, $this->hmacKey);

        self::assertNull($result);
    }

    #[Test]
    public function toArrayReturnsCompleteStructure(): void
    {
        $caches = [
            'config' => ['sha256' => str_repeat('a', 64), 'hmac' => str_repeat('b', 64)],
        ];

        $manifest = CacheManifest::write(
            cachePath: $this->tempDir,
            hmacKey: $this->hmacKey,
            schemaVersion: 1,
            frameworkVersion: '1.0.0-rc.2',
            appEnv: 'staging',
            invalidationKey: hash('sha256', 'test'),
            allowedClassesHash: hash('sha256', '[]'),
            caches: $caches,
            strict: true,
            encrypted: true,
        );

        $array = $manifest->toArray();

        self::assertArrayHasKey('schema_version', $array);
        self::assertArrayHasKey('framework_version', $array);
        self::assertArrayHasKey('app_env', $array);
        self::assertArrayHasKey('generated_at', $array);
        self::assertArrayHasKey('invalidation_key', $array);
        self::assertArrayHasKey('allowed_classes_hash', $array);
        self::assertArrayHasKey('caches', $array);
        self::assertArrayHasKey('strict', $array);
        self::assertArrayHasKey('encrypted', $array);
        self::assertSame(1, $array['schema_version']);
        self::assertSame('staging', $array['app_env']);
        self::assertTrue($array['strict']);
        self::assertTrue($array['encrypted']);
    }

    #[Test]
    public function loadReturnsNullForInvalidJson(): void
    {
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        file_put_contents($manifestPath, 'not valid json {{{');

        $result = CacheManifest::load($this->tempDir, $this->hmacKey);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForMissingHmacField(): void
    {
        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $data = [
            'schema_version' => 1,
            'framework_version' => '1.0.0',
            'app_env' => 'production',
            'generated_at' => time(),
            'invalidation_key' => hash('sha256', 'test'),
            'allowed_classes_hash' => hash('sha256', '[]'),
            'caches' => [],
            'strict' => false,
            'encrypted' => false,
            // No manifest_hmac field
        ];
        file_put_contents($manifestPath, json_encode($data, JSON_PRETTY_PRINT));

        $result = CacheManifest::load($this->tempDir, $this->hmacKey);

        self::assertNull($result);
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
