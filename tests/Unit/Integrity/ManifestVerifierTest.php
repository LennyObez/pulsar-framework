<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_put_contents;
use function hash_file;
use function is_dir;
use function is_link;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestEntry;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Integrity\VerificationResult;

use function random_bytes;
use function rmdir;
use function scandir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ManifestVerifier::class)]
final class ManifestVerifierTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_verify_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_verifies_unmodified_files_as_passed(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile('src/Router.php', '<?php class Router {}');

        $manifest = $this->buildManifest(['src/**/*.php'], []);
        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertInstanceOf(VerificationResult::class, $result);
        self::assertTrue($result->passed);
        self::assertSame(2, $result->verified);
        self::assertSame(0, $result->modified);
        self::assertSame(0, $result->missing);
        self::assertSame(0, $result->added);
    }

    #[Test]
    public function it_detects_modified_files(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $manifest = $this->buildManifest(['src/**/*.php'], []);

        // Modify the file after building the manifest
        $this->createFile('src/Kernel.php', '<?php class Kernel { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertFalse($result->passed);
        self::assertSame(0, $result->verified);
        self::assertSame(1, $result->modified);
        self::assertSame(0, $result->missing);

        $modifiedFile = $result->files[0];
        self::assertSame('src/Kernel.php', $modifiedFile->path);
        self::assertSame(FileVerificationStatus::Modified, $modifiedFile->status);
        self::assertNotNull($modifiedFile->expectedHash);
        self::assertNotNull($modifiedFile->actualHash);
        self::assertNotSame($modifiedFile->expectedHash, $modifiedFile->actualHash);
    }

    #[Test]
    public function it_detects_missing_files(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $manifest = $this->buildManifest(['src/**/*.php'], []);

        // Delete the file after building the manifest
        unlink($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel.php');
        rmdir($this->tempDir . DIRECTORY_SEPARATOR . 'src');

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertFalse($result->passed);
        self::assertSame(0, $result->verified);
        self::assertSame(0, $result->modified);
        self::assertSame(1, $result->missing);

        $missingFile = $result->files[0];
        self::assertSame('src/Kernel.php', $missingFile->path);
        self::assertSame(FileVerificationStatus::Missing, $missingFile->status);
        self::assertNotNull($missingFile->expectedHash);
        self::assertNull($missingFile->actualHash);
    }

    #[Test]
    public function it_detects_added_files(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $manifest = $this->buildManifest(['src/**/*.php'], []);

        // Add a new file in the same directory after building the manifest
        $this->createFile('src/NewFile.php', '<?php class NewFile {}');

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        // Added files do not cause failure (only modified and missing do)
        self::assertTrue($result->passed);
        self::assertSame(1, $result->verified);
        self::assertSame(0, $result->modified);
        self::assertSame(0, $result->missing);
        self::assertSame(1, $result->added);

        // Find the added file in results
        $addedFiles = array_filter(
            $result->files,
            static fn($f) => $f->status === FileVerificationStatus::Added,
        );
        self::assertCount(1, $addedFiles);

        $addedFile = array_values($addedFiles)[0];
        self::assertSame('src/NewFile.php', $addedFile->path);
        self::assertNull($addedFile->expectedHash);
        self::assertNotNull($addedFile->actualHash);
    }

    #[Test]
    public function it_detects_mixed_modifications_and_missing_files(): void
    {
        $this->createFile('src/A.php', '<?php class A {}');
        $this->createFile('src/B.php', '<?php class B {}');
        $this->createFile('src/C.php', '<?php class C {}');

        $manifest = $this->buildManifest(['src/**/*.php'], []);

        // Modify A, delete B, leave C intact
        $this->createFile('src/A.php', '<?php class A { /* tampered */ }');
        unlink($this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'B.php');

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->verified);
        self::assertSame(1, $result->modified);
        self::assertSame(1, $result->missing);
    }

    #[Test]
    public function it_passes_for_empty_manifest(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: time(),
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->verified);
        self::assertSame(0, $result->modified);
        self::assertSame(0, $result->missing);
        self::assertSame(0, $result->added);
    }

    #[Test]
    public function it_reports_verified_file_with_matching_hashes(): void
    {
        $content = '<?php class Verified {}';
        $this->createFile('src/Verified.php', $content);

        $manifest = $this->buildManifest(['src/**/*.php'], []);

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertCount(1, $result->files);

        $verifiedFile = $result->files[0];
        self::assertSame(FileVerificationStatus::Verified, $verifiedFile->status);
        self::assertNotNull($verifiedFile->expectedHash);
        self::assertNotNull($verifiedFile->actualHash);
        self::assertSame($verifiedFile->expectedHash, $verifiedFile->actualHash);
    }

    #[Test]
    public function it_handles_files_in_nested_directories(): void
    {
        $this->createFile('src/Core/Http/Request.php', '<?php class Request {}');
        $this->createFile('src/Core/Http/Response.php', '<?php class Response {}');

        $manifest = $this->buildManifest(['src/**/*.php'], []);

        // Modify one of the nested files
        $this->createFile('src/Core/Http/Request.php', '<?php class Request { /* modified */ }');

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->modified);
        self::assertSame(1, $result->verified);
    }

    #[Test]
    public function it_counts_totals_correctly(): void
    {
        $this->createFile('src/A.php', '<?php class A {}');
        $this->createFile('src/B.php', '<?php class B {}');
        $this->createFile('src/C.php', '<?php class C {}');

        $manifest = $this->buildManifest(['src/**/*.php'], []);

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertSame(3, $result->verified);
        self::assertCount(3, $result->files);
    }

    #[Test]
    public function it_verifies_with_manually_constructed_manifest(): void
    {
        $content = '<?php class Manual {}';
        $this->createFile('src/Manual.php', $content);

        $hash = hash_file('sha256', $this->tempDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Manual.php');
        self::assertIsString($hash);

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: time(),
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Manual.php', hash: $hash, size: strlen($content)),
            ],
        );

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertTrue($result->passed);
        self::assertSame(1, $result->verified);
    }

    #[Test]
    public function it_detects_wrong_hash_in_manually_constructed_manifest(): void
    {
        $this->createFile('src/Manual.php', '<?php class Manual {}');

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: time(),
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Manual.php', hash: 'incorrect_hash', size: 100),
            ],
        );

        $verifier = new ManifestVerifier($this->tempDir);
        $result = $verifier->verify($manifest);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->modified);
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private function buildManifest(array $include, array $exclude): IntegrityManifest
    {
        $builder = new ManifestBuilder($this->tempDir);

        return $builder->build($include, $exclude);
    }

    private function createFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($absolutePath, $content);
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
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
