<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;

final class FilenameSanitizerTest extends TestCase
{
    private FilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    #[Test]
    public function sanitizeRemovesNullBytes(): void
    {
        self::assertSame('test.php', $this->sanitizer->sanitize("test\0.php"));
    }

    #[Test]
    public function sanitizeRemovesPathTraversal(): void
    {
        self::assertSame('test.php', $this->sanitizer->sanitize('../../test.php'));
    }

    #[Test]
    public function sanitizeRemovesBackslashTraversal(): void
    {
        self::assertSame('test.php', $this->sanitizer->sanitize('..\\..\\test.php'));
    }

    #[Test]
    public function sanitizeRemovesControlCharacters(): void
    {
        self::assertSame('test.txt', $this->sanitizer->sanitize("test\x01\x02.txt"));
    }

    #[Test]
    public function sanitizeTrimsDotsAndWhitespace(): void
    {
        self::assertSame('test', $this->sanitizer->sanitize('...test...'));
    }

    #[Test]
    public function generateStorageNamePreservesExtension(): void
    {
        $name = $this->sanitizer->generateStorageName('document.pdf');

        self::assertStringEndsWith('.pdf', $name);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/', $name);
    }

    #[Test]
    public function generateStorageNameHandlesNoExtension(): void
    {
        $name = $this->sanitizer->generateStorageName('README');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $name);
    }

    #[Test]
    public function generateStorageNameProducesLowercaseExtension(): void
    {
        $name = $this->sanitizer->generateStorageName('photo.JPG');

        self::assertStringEndsWith('.jpg', $name);
    }
}
