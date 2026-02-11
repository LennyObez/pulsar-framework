<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;

use function strlen;

#[CoversClass(FilenameSanitizer::class)]
final class FilenameSanitizerTest extends TestCase
{
    private FilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    #[Test]
    public function sanitizeSimpleFilename(): void
    {
        $result = $this->sanitizer->sanitize('photo.jpg', str_repeat('a', 64));

        self::assertSame('aaaaaaaaaaaaaaaa_photo.jpg', $result);
    }

    #[Test]
    public function sanitizeLowercasesFilename(): void
    {
        $result = $this->sanitizer->sanitize('MyPhoto.PNG', str_repeat('b', 64));

        self::assertStringContainsString('myphoto.png', $result);
    }

    #[Test]
    public function sanitizeReplacesSpecialChars(): void
    {
        $result = $this->sanitizer->sanitize('my file (1).jpg', str_repeat('c', 64));

        self::assertStringContainsString('my-file-1-.jpg', $result);
    }

    #[Test]
    public function sanitizeCollapsesMultipleHyphens(): void
    {
        $result = $this->sanitizer->sanitize('file---name.txt', str_repeat('d', 64));

        self::assertStringContainsString('file-name.txt', $result);
    }

    #[Test]
    public function sanitizeStripsPathTraversal(): void
    {
        $result = $this->sanitizer->sanitize('../../../etc/passwd', str_repeat('e', 64));

        // basename() should strip path components
        self::assertStringNotContainsString('..', $result);
        self::assertStringContainsString('passwd', $result);
    }

    #[Test]
    public function sanitizePrependsHashPrefix(): void
    {
        $hash = hash('sha256', 'file content');
        $result = $this->sanitizer->sanitize('test.txt', $hash);

        self::assertStringStartsWith(substr($hash, 0, 16) . '_', $result);
    }

    #[Test]
    public function sanitizeTruncatesLongFilenames(): void
    {
        $longName = str_repeat('a', 300) . '.txt';
        $result = $this->sanitizer->sanitize($longName, str_repeat('f', 64));

        // 16 char prefix + underscore + max 200 chars = 217 max
        self::assertLessThanOrEqual(217, strlen($result));
    }

    #[Test]
    public function sanitizeTrimsLeadingTrailingHyphens(): void
    {
        $result = $this->sanitizer->sanitize('-leading.txt', str_repeat('g', 64));

        // After the hash prefix, the filename should not start with a hyphen
        $filename = explode('_', $result, 2)[1];
        self::assertStringStartsNotWith('-', $filename);
    }
}
