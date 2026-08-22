<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Media\Metadata\ExifExtractor;
use Pulsar\Extension\Cms\Media\Metadata\MediaMetadata;

use function bin2hex;
use function imagecreatetruecolor;
use function imagejpeg;
use function imagepng;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ExifExtractor::class)]
final class ExifExtractorTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/pulsar_exif_test_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->testDir)) {
            return;
        }

        // Safe: only removes files inside our dedicated temp dir created with random name
        $files = glob($this->testDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file) && str_starts_with(realpath($file) ?: '', realpath($this->testDir) ?: "\0")) {
                    unlink($file); // phpcs:ignore: test cleanup of our own temp files
                }
            }
        }

        rmdir($this->testDir);
    }

    #[Test]
    public function extract_returns_empty_metadata_for_unsupported_format(): void
    {
        $pngPath = $this->createTestPng();
        $extractor = new ExifExtractor(new NullLogger());

        $metadata = $extractor->extract($pngPath);

        // PNG doesn't support EXIF: should return empty metadata
        self::assertNull($metadata->cameraMake);
        self::assertNull($metadata->iso);
        self::assertNull($metadata->dateTaken);
    }

    #[Test]
    public function extract_returns_metadata_for_jpeg(): void
    {
        $jpegPath = $this->createTestJpeg();
        $extractor = new ExifExtractor(new NullLogger());

        $metadata = $extractor->extract($jpegPath);

        // GD-generated JPEGs have minimal EXIF, but extraction should succeed
        self::assertInstanceOf(MediaMetadata::class, $metadata);
    }

    #[Test]
    public function extractRaw_returns_empty_for_unsupported_format(): void
    {
        $pngPath = $this->createTestPng();
        $extractor = new ExifExtractor(new NullLogger());

        $raw = $extractor->extractRaw($pngPath);

        self::assertSame([], $raw);
    }

    #[Test]
    public function extractRaw_returns_array_for_jpeg(): void
    {
        $jpegPath = $this->createTestJpeg();
        $extractor = new ExifExtractor(new NullLogger());

        $raw = $extractor->extractRaw($jpegPath);

        // Should return some EXIF data (at minimum FILE section)
        self::assertIsArray($raw);
    }

    #[Test]
    #[DataProvider('supportedExtensionProvider')]
    public function isSupported_returns_true_for_supported_extensions(string $extension): void
    {
        $path = $this->testDir . '/test.' . $extension;
        // Create a minimal file so mime_content_type can work
        $image = imagecreatetruecolor(1, 1);
        self::assertNotFalse($image);
        imagejpeg($image, $path);

        $extractor = new ExifExtractor(new NullLogger());

        self::assertTrue($extractor->isSupported($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedExtensionProvider(): iterable
    {
        yield 'jpg' => ['jpg'];
        yield 'jpeg' => ['jpeg'];
    }

    #[Test]
    public function isSupported_returns_false_for_png(): void
    {
        $pngPath = $this->createTestPng();
        $extractor = new ExifExtractor(new NullLogger());

        self::assertFalse($extractor->isSupported($pngPath));
    }

    #[Test]
    public function extract_handles_nonexistent_file_gracefully(): void
    {
        $extractor = new ExifExtractor(new NullLogger());

        $metadata = $extractor->extract('/nonexistent/path/file.jpg');

        self::assertNull($metadata->cameraMake);
    }

    #[Test]
    public function extractRaw_handles_nonexistent_file_gracefully(): void
    {
        $extractor = new ExifExtractor(new NullLogger());

        $raw = $extractor->extractRaw('/nonexistent/path/file.jpg');

        self::assertSame([], $raw);
    }

    private function createTestJpeg(): string
    {
        $image = imagecreatetruecolor(10, 10);
        self::assertNotFalse($image);

        $path = $this->testDir . '/test_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 90);

        return $path;
    }

    private function createTestPng(): string
    {
        $image = imagecreatetruecolor(10, 10);
        self::assertNotFalse($image);

        $path = $this->testDir . '/test_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        return $path;
    }
}
