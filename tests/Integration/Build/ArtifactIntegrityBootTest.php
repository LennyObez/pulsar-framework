<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\ArtifactIntegrityVerifier;
use Pulsar\Build\BuildArtifactLoader;
use Pulsar\Build\BuildManifest;
use Pulsar\Build\VerificationStatus;

use function bin2hex;
use function file_put_contents;
use function hash;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function strlen;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Integration test: tampered artifacts are rejected when verification is enabled.
 *
 * Simulates the integrity verification during Kernel boot when
 * PULSAR_VERIFY_ARTIFACTS=1.
 */
#[CoversClass(ArtifactIntegrityVerifier::class)]
#[CoversClass(BuildArtifactLoader::class)]
final class ArtifactIntegrityBootTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_boot_' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function validArtifactsPassBootVerification(): void
    {
        $configContent = '<?php return ["app" => "test"];';
        $configHash = hash('sha256', $configContent);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $configContent);

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', $configHash, strlen($configContent)),
            ],
            contentHashes: [],
        );

        // Write manifest JSON to disk
        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            $manifest->toJson(),
        );

        $loader = new BuildArtifactLoader($this->cacheDir);

        self::assertTrue($loader->hasArtifacts());

        $loadedManifest = $loader->loadManifest();
        self::assertNotNull($loadedManifest);

        $result = $loader->verifyIntegrity($loadedManifest);
        self::assertNotNull($result);
        self::assertTrue($result->passed);
    }

    #[Test]
    public function tamperedArtifactFailsBootVerification(): void
    {
        $originalContent = '<?php return ["app" => "genuine"];';
        $originalHash = hash('sha256', $originalContent);

        // Write tampered content to disk
        $tamperedContent = '<?php return ["app" => "compromised"];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $tamperedContent);

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

        $loader = new BuildArtifactLoader($this->cacheDir);
        $loadedManifest = $loader->loadManifest();
        self::assertNotNull($loadedManifest);

        $result = $loader->verifyIntegrity($loadedManifest);
        self::assertNotNull($result);
        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Modified, $result->entries['config']);
    }

    #[Test]
    public function missingArtifactFailsBootVerification(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'routes' => new ArtifactEntry('routes.compiled.php', 'abc', 100),
            ],
            contentHashes: [],
        );

        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
            $manifest->toJson(),
        );

        $loader = new BuildArtifactLoader($this->cacheDir);
        $loadedManifest = $loader->loadManifest();
        self::assertNotNull($loadedManifest);

        $result = $loader->verifyIntegrity($loadedManifest);
        self::assertNotNull($result);
        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Missing, $result->entries['routes']);
    }

    #[Test]
    public function noManifestReturnsNullVerification(): void
    {
        // Cache directory exists but no manifest file
        $loader = new BuildArtifactLoader($this->cacheDir);

        self::assertFalse($loader->hasArtifacts());
        self::assertNull($loader->loadManifest());
        self::assertNull($loader->verifyIntegrity());
    }

    #[Test]
    public function requiredArtifactsCheckReportsMissing(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', 'hash', 100),
                // 'routes' is missing
            ],
            contentHashes: [],
        );

        $loader = new BuildArtifactLoader($this->cacheDir);
        $missing = $loader->checkRequiredArtifacts($manifest);

        self::assertContains('routes', $missing);
        self::assertNotContains('config', $missing);
    }

    #[Test]
    public function allRequiredArtifactsPresentReportsNone(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', 'hash_c', 100),
                'routes' => new ArtifactEntry('routes.compiled.php', 'hash_r', 200),
            ],
            contentHashes: [],
        );

        $loader = new BuildArtifactLoader($this->cacheDir);
        $missing = $loader->checkRequiredArtifacts($manifest);

        self::assertSame([], $missing);
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
