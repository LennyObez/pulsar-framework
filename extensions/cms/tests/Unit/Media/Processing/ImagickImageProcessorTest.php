<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Processing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Media\Processing\ImagickImageProcessor;

use function bin2hex;
use function getimagesize;
use function imagecreatetruecolor;
use function imagejpeg;
use function imagepng;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;

#[CoversClass(ImagickImageProcessor::class)]
#[RequiresPhpExtension('imagick')]
final class ImagickImageProcessorTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/pulsar_imagick_test_' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->testDir)) {
            return;
        }

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
    public function isAvailable_returns_true_when_imagick_loaded(): void
    {
        self::assertTrue(ImagickImageProcessor::isAvailable());
    }

    #[Test]
    public function resize_jpeg_maintains_dimensions(): void
    {
        $source = $this->createTestJpeg(400, 300);
        $processor = $this->createProcessor();

        $result = $processor->resize($source, 200, null, 'jpeg');

        self::assertFileExists($result);
        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(200, $info[0]);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function resize_does_not_upscale(): void
    {
        $source = $this->createTestJpeg(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->resize($source, 500, null, 'jpeg');

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertLessThanOrEqual(100, $info[0]);
    }

    #[Test]
    public function resize_to_png_format(): void
    {
        $source = $this->createTestJpeg(200, 200);
        $processor = $this->createProcessor();

        $result = $processor->resize($source, 100, null, 'png');

        self::assertFileExists($result);
        self::assertStringEndsWith('.png', $result);
        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    #[Test]
    public function resize_preserves_explicit_height(): void
    {
        $source = $this->createTestJpeg(400, 400);
        $processor = $this->createProcessor();

        $result = $processor->resize($source, 200, 150, 'jpeg');

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(200, $info[0]);
        self::assertSame(150, $info[1]);
    }

    #[Test]
    public function generateBlurPlaceholder_returns_data_uri(): void
    {
        $source = $this->createTestJpeg(200, 200);
        $processor = $this->createProcessor();

        $result = $processor->generateBlurPlaceholder($source);

        self::assertStringStartsWith('data:image/webp;base64,', $result);
    }

    #[Test]
    public function extractExif_returns_array_for_jpeg(): void
    {
        $source = $this->createTestJpeg(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->extractExif($source);

        self::assertIsArray($result);
    }

    #[Test]
    public function extractExif_returns_empty_for_png(): void
    {
        $source = $this->createTestPng(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->extractExif($source);

        self::assertSame([], $result);
    }

    #[Test]
    public function stripExif_produces_valid_output(): void
    {
        $source = $this->createTestJpeg(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->stripExif($source);

        self::assertFileExists($result);
        self::assertStringEndsWith('.jpeg', $result);
        $info = getimagesize($result);
        self::assertNotFalse($info);
    }

    #[Test]
    public function stripExif_preserves_format_for_png(): void
    {
        $source = $this->createTestPng(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->stripExif($source);

        self::assertStringEndsWith('.png', $result);
        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    #[Test]
    public function resize_to_webp(): void
    {
        $source = $this->createTestJpeg(200, 200);
        $processor = $this->createProcessor();

        $result = $processor->resize($source, 100, null, 'webp');

        self::assertFileExists($result);
        self::assertStringEndsWith('.webp', $result);
    }

    #[Test]
    public function uses_configured_jpeg_quality(): void
    {
        $lowQuality = $this->createProcessor(jpegQuality: 10);
        $highQuality = $this->createProcessor(jpegQuality: 100);

        $source = $this->createTestJpeg(200, 200);

        $lowResult = $lowQuality->resize($source, 100, null, 'jpeg');
        $highResult = $highQuality->resize($source, 100, null, 'jpeg');

        // Lower quality should produce a smaller file
        self::assertLessThan(
            filesize($highResult),
            filesize($lowResult),
        );
    }

    private function createTestJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $bg = imagecolorallocate($image, 100, 150, 200);

        if ($bg !== false) {
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $bg);
        }

        $path = $this->testDir . '/test_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 90);

        return $path;
    }

    private function createTestPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $path = $this->testDir . '/test_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        return $path;
    }

    private function createProcessor(int $jpegQuality = 85): ImagickImageProcessor
    {
        return new ImagickImageProcessor(
            new MediaConfig(jpegQuality: $jpegQuality),
        );
    }
}
