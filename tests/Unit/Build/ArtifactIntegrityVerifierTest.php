<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Build;

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

#[CoversClass(ArtifactIntegrityVerifier::class)]
final class ArtifactIntegrityVerifierTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_verifier_test_' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function verifyPassesForValidArtifacts(): void
    {
        $content = '<?php return [];';
        $expectedHash = hash('sha256', $content);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'extensions.manifest.php', $content);

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'extensions' => new ArtifactEntry('extensions.manifest.php', $expectedHash, strlen($content)),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertTrue($result->passed);
        self::assertSame(VerificationStatus::Ok, $result->entries['extensions']);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function verifyDetectsModifiedArtifact(): void
    {
        $originalContent = '<?php return ["original"];';
        $tamperedContent = '<?php return ["tampered"];';
        $originalHash = hash('sha256', $originalContent);

        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'routes.compiled.php', $tamperedContent);

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'routes' => new ArtifactEntry('routes.compiled.php', $originalHash, strlen($originalContent)),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Modified, $result->entries['routes']);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('modified', $result->errors[0]);
    }

    #[Test]
    public function verifyDetectsMissingArtifact(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'container' => new ArtifactEntry('container.compiled.php', 'abc123', 1024),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Missing, $result->entries['container']);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('missing', $result->errors[0]);
    }

    #[Test]
    public function verifyReportsCorrectStatusPerArtifact(): void
    {
        // Create one valid artifact
        $validContent = '<?php return "valid";';
        $validHash = hash('sha256', $validContent);
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'valid.php', $validContent);

        // Create one modified artifact
        $originalHash = hash('sha256', 'original');
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'modified.php', 'tampered');

        // Missing artifact: do not create it

        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [
                'valid' => new ArtifactEntry('valid.php', $validHash, strlen($validContent)),
                'modified' => new ArtifactEntry('modified.php', $originalHash, 8),
                'missing' => new ArtifactEntry('missing.php', 'fff999', 512),
            ],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertFalse($result->passed);
        self::assertSame(VerificationStatus::Ok, $result->entries['valid']);
        self::assertSame(VerificationStatus::Modified, $result->entries['modified']);
        self::assertSame(VerificationStatus::Missing, $result->entries['missing']);
        self::assertCount(2, $result->errors);
    }

    #[Test]
    public function verifyPassesWithEmptyManifest(): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: [],
            contentHashes: [],
        );

        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $this->cacheDir);

        self::assertTrue($result->passed);
        self::assertSame([], $result->entries);
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
