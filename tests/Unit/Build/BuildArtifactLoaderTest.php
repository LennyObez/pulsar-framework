<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\BuildArtifactLoader;
use Pulsar\Build\BuildManifest;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function file_put_contents;
use function json_encode;
use function mkdir;
use function putenv;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

#[CoversClass(BuildArtifactLoader::class)]
final class BuildArtifactLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_build_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        putenv('PULSAR_VERIFY_ARTIFACTS');
    }

    #[Test]
    public function hasArtifactsReturnsFalseWhenNoManifest(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertFalse($loader->hasArtifacts());
    }

    #[Test]
    public function hasArtifactsReturnsTrueWhenManifestExists(): void
    {
        $this->writeManifestFile();
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertTrue($loader->hasArtifacts());
    }

    #[Test]
    public function cacheExistsReturnsTrueWhenDirectoryExists(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertTrue($loader->cacheExists());
    }

    #[Test]
    public function cacheExistsReturnsFalseWhenDirectoryDoesNotExist(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent');

        self::assertFalse($loader->cacheExists());
    }

    #[Test]
    public function loadManifestReturnsNullWhenFileDoesNotExist(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertNull($loader->loadManifest());
    }

    #[Test]
    public function loadManifestReturnsBuildManifest(): void
    {
        $this->writeManifestFile([
            'version' => 1,
            'algorithm' => 'sha256',
            'artifacts' => [
                'config' => ['path' => 'config.php', 'hash' => 'abc123', 'size' => 1024],
            ],
            'contentHashes' => ['src/App.php' => 'def456'],
        ]);

        $loader = new BuildArtifactLoader($this->tempDir);
        $manifest = $loader->loadManifest();

        self::assertInstanceOf(BuildManifest::class, $manifest);
        self::assertSame(1, $manifest->version);
        self::assertSame('sha256', $manifest->algorithm);
        self::assertArrayHasKey('config', $manifest->artifacts);
    }

    #[Test]
    public function verifyIntegrityReturnsNullWhenNoManifest(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertNull($loader->verifyIntegrity());
    }

    #[Test]
    public function verifySignatureReturnsTrueWhenNoSignature(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [],
            contentHashes: [],
            signature: null,
        );

        $hmac = $this->createStub(HmacInterface::class);
        $keyProvider = $this->createStub(KeyProviderInterface::class);

        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertTrue($loader->verifySignature($manifest, $hmac, $keyProvider));
    }

    #[Test]
    public function loadExtensionManifestReturnsNullWhenFileDoesNotExist(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertNull($loader->loadExtensionManifest());
    }

    #[Test]
    public function loadEventMapReturnsNullWhenFileDoesNotExist(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertNull($loader->loadEventMap());
    }

    #[Test]
    public function loadI18nCatalogIndexReturnsNullWhenFileDoesNotExist(): void
    {
        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertNull($loader->loadI18nCatalogIndex());
    }

    #[Test]
    public function isVerificationEnabledReturnsFalseByDefault(): void
    {
        putenv('PULSAR_VERIFY_ARTIFACTS');

        self::assertFalse(BuildArtifactLoader::isVerificationEnabled());
    }

    #[Test]
    public function isVerificationEnabledReturnsTrueForOne(): void
    {
        putenv('PULSAR_VERIFY_ARTIFACTS=1');

        self::assertTrue(BuildArtifactLoader::isVerificationEnabled());
    }

    #[Test]
    public function isVerificationEnabledReturnsTrueForTrue(): void
    {
        putenv('PULSAR_VERIFY_ARTIFACTS=true');

        self::assertTrue(BuildArtifactLoader::isVerificationEnabled());
    }

    #[Test]
    public function isVerificationEnabledReturnsFalseForOtherValues(): void
    {
        putenv('PULSAR_VERIFY_ARTIFACTS=yes');

        self::assertFalse(BuildArtifactLoader::isVerificationEnabled());
    }

    #[Test]
    public function requiredArtifactsReturnsExpectedKeys(): void
    {
        $required = BuildArtifactLoader::requiredArtifacts();

        self::assertContains('config', $required);
        self::assertContains('routes', $required);
    }

    #[Test]
    public function checkRequiredArtifactsReturnsEmptyWhenAllPresent(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.php', 'abc', 100),
                'routes' => new ArtifactEntry('routes.php', 'def', 200),
            ],
            contentHashes: [],
        );

        $loader = new BuildArtifactLoader($this->tempDir);

        self::assertSame([], $loader->checkRequiredArtifacts($manifest));
    }

    #[Test]
    public function checkRequiredArtifactsReturnsMissingKeys(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [],
            contentHashes: [],
        );

        $loader = new BuildArtifactLoader($this->tempDir);
        $missing = $loader->checkRequiredArtifacts($manifest);

        self::assertContains('config', $missing);
        self::assertContains('routes', $missing);
    }

    #[Test]
    public function checkRequiredArtifactsReportsPartiallyMissing(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.php', 'abc', 100),
            ],
            contentHashes: [],
        );

        $loader = new BuildArtifactLoader($this->tempDir);
        $missing = $loader->checkRequiredArtifacts($manifest);

        self::assertNotContains('config', $missing);
        self::assertContains('routes', $missing);
    }

    #[Test]
    public function loadEventMapReturnsArrayWhenFileExists(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'events_map.php';
        file_put_contents($path, '<?php return ["App\\\\Event\\\\UserCreated" => ["listeners" => [], "requiresEnvelope" => false, "stormOverride" => null, "listenerModuleIds" => []]];');

        $loader = new BuildArtifactLoader($this->tempDir);
        $map = $loader->loadEventMap();

        self::assertIsArray($map);
        self::assertArrayHasKey('App\\Event\\UserCreated', $map);
    }

    #[Test]
    public function loadI18nCatalogIndexReturnsArrayWhenFileExists(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'i18n_catalog_index.php';
        file_put_contents($path, '<?php return ["en" => ["messages" => "/path/to/en.php"]];');

        $loader = new BuildArtifactLoader($this->tempDir);
        $index = $loader->loadI18nCatalogIndex();

        self::assertIsArray($index);
        self::assertArrayHasKey('en', $index);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function writeManifestFile(?array $data = null): void
    {
        $data ??= [
            'version' => 1,
            'algorithm' => 'sha256',
            'artifacts' => [],
            'contentHashes' => [],
        ];

        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );
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
