<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\ArtifactIntegrityVerifier;
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
 * Integration test: verify `--verify` semantics — current artifacts pass, stale fail.
 *
 * Simulates the artifact verification that `pulsar build --verify` performs.
 */
#[CoversClass(ArtifactIntegrityVerifier::class)]
#[CoversClass(BuildManifest::class)]
final class StaleArtifactDetectionTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_stale_test_' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function currentArtifactsPassVerification(): void
    {
        $content = '<?php return ["compiled" => true];';
        $contentHash = hash('sha256', $content);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $content);

        $routeContent = '<?php return [["path" => "/", "method" => "GET"]];';
        $routeHash = hash('sha256', $routeContent);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'routes.compiled.php', $routeContent);

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', $contentHash, strlen($content)),
                'routes' => new ArtifactEntry('routes.compiled.php', $routeHash, strlen($routeContent)),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        // Simulates: `pulsar build --verify` exits 0
        self::assertTrue($result->passed);
    }

    #[Test]
    public function staleArtifactsFailVerification(): void
    {
        $originalContent = '<?php return ["version" => 1];';
        $originalHash = hash('sha256', $originalContent);

        // Write modified (stale) content
        $staleContent = '<?php return ["version" => 2, "modified" => true];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $staleContent);

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'config' => new ArtifactEntry('config.compiled.php', $originalHash, strlen($originalContent)),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        // Simulates: `pulsar build --verify` exits 1
        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Modified, $result->entries['config']);
    }

    #[Test]
    public function deletedArtifactReportsMissing(): void
    {
        // Manifest references an artifact that no longer exists on disk
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'extensions' => new ArtifactEntry('extensions.manifest.php', 'abc123', 512),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Missing, $result->entries['extensions']);
    }

    #[Test]
    public function mixedStatusReportsAllCorrectly(): void
    {
        // One valid artifact
        $validContent = '<?php return "valid";';
        $validHash = hash('sha256', $validContent);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'valid.php', $validContent);

        // One stale artifact
        $originalHash = hash('sha256', 'original');
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'stale.php', 'modified');

        // One missing artifact (not written)

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'valid' => new ArtifactEntry('valid.php', $validHash, strlen($validContent)),
                'stale' => new ArtifactEntry('stale.php', $originalHash, 8),
                'gone' => new ArtifactEntry('gone.php', 'deadbeef', 100),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Ok, $result->entries['valid']);
        self::assertSame(VerificationStatus::Modified, $result->entries['stale']);
        self::assertSame(VerificationStatus::Missing, $result->entries['gone']);
        self::assertCount(2, $result->errors);
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
