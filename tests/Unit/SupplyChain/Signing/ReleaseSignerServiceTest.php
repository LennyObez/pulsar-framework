<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Signing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Signing\ArtifactSigner;
use Pulsar\SupplyChain\Signing\ReleaseSignerService;
use RuntimeException;

use function file_put_contents;
use function is_array;
use function mkdir;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ReleaseSignerService::class)]
final class ReleaseSignerServiceTest extends TestCase
{
    private ReleaseSignerService $service;
    private string $secretKey;
    private string $publicKey;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->service = new ReleaseSignerService(new ArtifactSigner());

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);

        $this->tempDir = sys_get_temp_dir() . '/pulsar_release_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up self-created temp files
        $files = glob($this->tempDir . '/*');

        if (is_array($files)) {
            foreach ($files as $file) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use — test cleanup of self-created temp files
                unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function signDirectorySignsAllArtifacts(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'phar content');
        file_put_contents($this->tempDir . '/release.zip', 'zip content');
        file_put_contents($this->tempDir . '/archive.tar', 'tar content');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        self::assertCount(3, $manifests);

        $paths = array_column(array_map(
            static fn($m) => ['path' => $m->artifactPath],
            $manifests,
        ), 'path');

        self::assertContains('app.phar', $paths);
        self::assertContains('release.zip', $paths);
        self::assertContains('archive.tar', $paths);
    }

    #[Test]
    public function signDirectoryReturnsEmptyForNoArtifacts(): void
    {
        // Only a text file, no PHAR/ZIP/TAR
        file_put_contents($this->tempDir . '/readme.txt', 'hello');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        self::assertSame([], $manifests);
    }

    #[Test]
    public function signDirectoryManifestsContainValidSignatures(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'test content for signing');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        self::assertCount(1, $manifests);
        self::assertSame('ed25519', $manifests[0]->algorithm);
        self::assertNotEmpty($manifests[0]->signature);
        self::assertNotEmpty($manifests[0]->publicKey);
    }

    #[Test]
    public function verifyDirectoryValidatesAllSignatures(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'content A');
        file_put_contents($this->tempDir . '/lib.zip', 'content B');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);
        $result = $this->service->verifyDirectory($this->tempDir, $manifests, $this->publicKey);

        self::assertCount(2, $result['valid']);
        self::assertSame([], $result['invalid']);
        self::assertSame([], $result['missing']);
    }

    #[Test]
    public function verifyDirectoryDetectsTamperedFile(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'original');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        // Tamper with file
        file_put_contents($this->tempDir . '/app.phar', 'TAMPERED');

        $result = $this->service->verifyDirectory($this->tempDir, $manifests, $this->publicKey);

        self::assertSame([], $result['valid']);
        self::assertCount(1, $result['invalid']);
        self::assertContains('app.phar', $result['invalid']);
    }

    #[Test]
    public function verifyDirectoryDetectsMissingFile(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'content');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        // nosemgrep: php.lang.security.unlink-use.unlink-use — test deliberately removes self-created temp file
        unlink($this->tempDir . '/app.phar');

        $result = $this->service->verifyDirectory($this->tempDir, $manifests, $this->publicKey);

        self::assertSame([], $result['valid']);
        self::assertSame([], $result['invalid']);
        self::assertCount(1, $result['missing']);
        self::assertContains('app.phar', $result['missing']);
    }

    #[Test]
    public function signDirectoryThrowsForNonexistentDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        (void) $this->service->signDirectory('/nonexistent/directory', $this->secretKey);
    }

    #[Test]
    public function signDirectoryThrowsForPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal');

        (void) $this->service->signDirectory('/some/../../../etc', $this->secretKey);
    }

    #[Test]
    public function verifyDirectoryThrowsForPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal');

        (void) $this->service->verifyDirectory('/some/../../../etc', [], $this->publicKey);
    }

    #[Test]
    public function verifyDirectoryWithWrongKeyFailsAllSignatures(): void
    {
        file_put_contents($this->tempDir . '/app.phar', 'content');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        // Use a different key
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherPublic = sodium_crypto_sign_publickey($otherKeypair);

        $result = $this->service->verifyDirectory($this->tempDir, $manifests, $otherPublic);

        self::assertSame([], $result['valid']);
        self::assertCount(1, $result['invalid']);
    }

    #[Test]
    public function signDirectoryUsesRelativePaths(): void
    {
        file_put_contents($this->tempDir . '/release.phar', 'content');

        $manifests = $this->service->signDirectory($this->tempDir, $this->secretKey);

        self::assertCount(1, $manifests);
        // Path should be relative (no leading slash or full path)
        self::assertSame('release.phar', $manifests[0]->artifactPath);
    }
}
