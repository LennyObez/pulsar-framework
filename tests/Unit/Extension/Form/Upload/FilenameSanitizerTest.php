<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;

#[CoversClass(FilenameSanitizer::class)]
final class FilenameSanitizerTest extends TestCase
{
    private FilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    // ── sanitize() ───────────────────────────────────────────────────

    #[Test]
    public function sanitizePreservesSafeFilename(): void
    {
        self::assertSame('document.pdf', $this->sanitizer->sanitize('document.pdf'));
    }

    #[Test]
    public function sanitizePreservesFilenameWithSpaces(): void
    {
        self::assertSame('my document.pdf', $this->sanitizer->sanitize('my document.pdf'));
    }

    #[Test]
    #[DataProvider('nullByteProvider')]
    public function sanitizeRemovesNullBytes(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nullByteProvider(): iterable
    {
        yield 'null byte in middle' => ["file\x00name.txt", 'filename.txt'];
        yield 'null byte at start' => ["\x00file.txt", 'file.txt'];
        yield 'null byte at end' => ["file.txt\x00", 'file.txt'];
        yield 'multiple null bytes' => ["\x00f\x00i\x00le\x00", 'file'];
    }

    #[Test]
    #[DataProvider('pathTraversalProvider')]
    public function sanitizeRemovesPathTraversal(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathTraversalProvider(): iterable
    {
        yield 'unix traversal' => ['../../../etc/passwd', 'etcpasswd'];
        yield 'windows traversal' => ['..\\..\\windows\\system32', 'windowssystem32'];
        yield 'forward slash' => ['path/to/file.txt', 'pathtofile.txt'];
        yield 'backslash' => ['path\\to\\file.txt', 'pathtofile.txt'];
        yield 'mixed traversal' => ['../path\\..\\file.txt', 'pathfile.txt'];
    }

    #[Test]
    #[DataProvider('controlCharacterProvider')]
    public function sanitizeRemovesControlCharacters(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controlCharacterProvider(): iterable
    {
        yield 'tab character' => ["file\tname.txt", 'filename.txt'];
        yield 'newline' => ["file\nname.txt", 'filename.txt'];
        yield 'carriage return' => ["file\rname.txt", 'filename.txt'];
        yield 'bell character' => ["file\x07name.txt", 'filename.txt'];
        yield 'delete character' => ["file\x7Fname.txt", 'filename.txt'];
        yield 'form feed' => ["file\x0Cname.txt", 'filename.txt'];
    }

    #[Test]
    public function sanitizeTrimsWhitespace(): void
    {
        self::assertSame('file.txt', $this->sanitizer->sanitize('  file.txt  '));
    }

    #[Test]
    public function sanitizeTrimsLeadingAndTrailingDots(): void
    {
        self::assertSame('file.txt', $this->sanitizer->sanitize('...file.txt...'));
    }

    #[Test]
    public function sanitizeHandlesEmptyStringAfterStripping(): void
    {
        // All characters are stripped/trimmed
        self::assertSame('', $this->sanitizer->sanitize('...'));
    }

    #[Test]
    public function sanitizePreservesInternalDots(): void
    {
        self::assertSame('file.backup.tar.gz', $this->sanitizer->sanitize('file.backup.tar.gz'));
    }

    #[Test]
    public function sanitizeHandlesUnicodeCharacters(): void
    {
        self::assertSame('fichier.txt', $this->sanitizer->sanitize('fichier.txt'));
    }

    #[Test]
    public function sanitizeHandlesCombinedAttackVector(): void
    {
        $malicious = "../\x00..\\/etc\x07/passwd\x00.php";
        $result = $this->sanitizer->sanitize($malicious);

        self::assertStringNotContainsString('..', $result);
        self::assertStringNotContainsString("\x00", $result);
        self::assertStringNotContainsString('/', $result);
        self::assertStringNotContainsString('\\', $result);
    }

    // ── generateStorageName() ────────────────────────────────────────

    #[Test]
    public function generateStorageNameReturnsUuidBasedFilename(): void
    {
        $storageName = $this->sanitizer->generateStorageName('photo.jpg');

        // Should match UUID pattern with .jpg extension
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.jpg$/',
            $storageName,
        );
    }

    #[Test]
    public function generateStorageNamePreservesExtensionInLowercase(): void
    {
        $storageName = $this->sanitizer->generateStorageName('PHOTO.JPG');

        self::assertStringEndsWith('.jpg', $storageName);
    }

    #[Test]
    public function generateStorageNameHandlesNoExtension(): void
    {
        $storageName = $this->sanitizer->generateStorageName('Makefile');

        // No extension, so just UUID
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $storageName,
        );
    }

    #[Test]
    public function generateStorageNameSanitizesBeforeExtracting(): void
    {
        $storageName = $this->sanitizer->generateStorageName("../../../evil\x00.php");

        // Should sanitize the path traversal and null bytes before extracting extension
        self::assertStringEndsWith('.php', $storageName);
        self::assertStringNotContainsString('..', $storageName);
    }

    #[Test]
    public function generateStorageNameProducesUniqueNames(): void
    {
        $names = [];
        for ($i = 0; $i < 10; $i++) {
            $names[] = $this->sanitizer->generateStorageName('file.txt');
        }

        // All names should be unique
        self::assertCount(10, array_unique($names));
    }

    #[Test]
    public function generateStorageNameUsesUuidV4Format(): void
    {
        $storageName = $this->sanitizer->generateStorageName('test.png');
        $uuid = pathinfo($storageName, PATHINFO_FILENAME);

        // Version 4 UUID: third group starts with 4
        $parts = explode('-', $uuid);
        self::assertCount(5, $parts);
        self::assertStringStartsWith('4', $parts[2]);

        // Variant bits: fourth group starts with 8, 9, a, or b
        self::assertMatchesRegularExpression('/^[89ab]/', $parts[3]);
    }

    #[Test]
    public function generateStorageNameHandlesDoubleExtension(): void
    {
        $storageName = $this->sanitizer->generateStorageName('file.tar.gz');

        // pathinfo extracts the last extension
        self::assertStringEndsWith('.gz', $storageName);
    }

    #[Test]
    public function generateStorageNameHandlesDotFile(): void
    {
        // .gitignore — after trimming dots, becomes 'gitignore'
        $storageName = $this->sanitizer->generateStorageName('.gitignore');

        // The sanitizer trims dots, so .gitignore becomes 'gitignore' with no extension
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $storageName,
        );
    }
}
