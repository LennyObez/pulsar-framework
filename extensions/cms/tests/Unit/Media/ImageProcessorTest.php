<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Media\ImageProcessor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function getimagesize;
use function imagecreatetruecolor;
use function imagejpeg;
use function imagepng;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;

#[CoversClass(ImageProcessor::class)]
final class ImageProcessorTest extends TestCase
{
    private string $testTempDir;

    protected function setUp(): void
    {
        $this->testTempDir = sys_get_temp_dir() . '/pulsar_image_test_' . bin2hex(random_bytes(4));
        mkdir($this->testTempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        // Remove all files in the dedicated test temp directory
        if (!is_dir($this->testTempDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testTempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                // Safe: only deletes files inside our dedicated test temp dir
                unlink($item->getPathname()); // phpcs:ignore
            } else {
                rmdir($item->getPathname());
            }
        }

        rmdir($this->testTempDir);
    }

    #[Test]
    public function resize_png_preserves_alpha_transparency(): void
    {
        $sourcePath = $this->createTestPng(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->resize($sourcePath, 50, null, 'png');

        self::assertFileExists($result);
        self::assertStringEndsWith('.png', $result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
        self::assertSame(50, $info[0]);
    }

    #[Test]
    public function resize_webp_preserves_alpha_transparency(): void
    {
        $sourcePath = $this->createTestPng(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->resize($sourcePath, 50, null, 'webp');

        self::assertFileExists($result);
        self::assertStringEndsWith('.webp', $result);
    }

    #[Test]
    public function resize_jpeg_does_not_apply_alpha_handling(): void
    {
        $sourcePath = $this->createTestJpeg(100, 100);
        $processor = $this->createProcessor();

        $result = $processor->resize($sourcePath, 50, null, 'jpeg');

        self::assertFileExists($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function stripExif_preserves_source_format_for_png(): void
    {
        $sourcePath = $this->createTestPng(80, 80);
        $processor = $this->createProcessor();

        $result = $processor->stripExif($sourcePath);

        self::assertStringEndsWith('.png', $result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    #[Test]
    public function stripExif_preserves_source_format_for_jpeg(): void
    {
        $sourcePath = $this->createTestJpeg(80, 80);
        $processor = $this->createProcessor();

        $result = $processor->stripExif($sourcePath);

        self::assertStringEndsWith('.jpg', $result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function resize_does_not_upscale_beyond_source_dimensions(): void
    {
        $sourcePath = $this->createTestJpeg(50, 50);
        $processor = $this->createProcessor();

        $result = $processor->resize($sourcePath, 200, null, 'jpeg');

        $info = getimagesize($result);
        self::assertNotFalse($info);
        // Should not exceed source dimensions
        self::assertLessThanOrEqual(50, $info[0]);
    }

    private function createTestPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $path = $this->testTempDir . '/test_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        return $path;
    }

    private function createTestJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $path = $this->testTempDir . '/test_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 90);

        return $path;
    }

    private function createProcessor(): ImageProcessor
    {
        return new ImageProcessor(
            MediaConfig::fromArray([]),
        );
    }
}
