<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\ImageVariantConfig;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\ImageVariantGenerator;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;

#[CoversClass(ImageVariantGenerator::class)]
final class ImageVariantGeneratorTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_variant_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $files = glob($this->tmpDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function test_generates_variants_for_each_config(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $configs = [
            new ImageVariantConfig('thumbnail', 150, 150, 'jpeg', 80),
            new ImageVariantConfig('medium', 600, 600, 'jpeg', 80),
        ];

        // Create resized temp files that the processor will return
        $thumbResized = $this->createJpeg(150, 113);
        $mediumResized = $this->createJpeg(600, 450);

        $processor = $this->createStub(ImageProcessorInterface::class);
        $processor->method('resize')->willReturnOnConsecutiveCalls($thumbResized, $mediumResized);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);

        self::assertCount(2, $variants);
        self::assertContainsOnlyInstancesOf(ImageVariant::class, $variants);
    }

    #[Test]
    public function test_skips_variants_when_original_is_smaller(): void
    {
        $source = $this->createJpeg(100, 80);
        $configs = [
            new ImageVariantConfig('thumbnail', 150, 150, 'jpeg', 80),
            new ImageVariantConfig('large', 1200, 1200, 'jpeg', 80),
        ];

        $processor = $this->createStub(ImageProcessorInterface::class);
        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);

        // 100x80 image is smaller than both 150x150 and 1200x1200
        self::assertCount(0, $variants);
    }

    #[Test]
    public function test_uses_default_variants_when_none_configured(): void
    {
        $source = $this->createJpeg(2000, 1500);

        // Default variants: thumbnail 150x150, medium 600x600, large 1200x1200
        // Source is larger than all three, so all three should be generated
        $thumb = $this->createJpeg(150, 113);
        $medium = $this->createJpeg(600, 450);
        $large = $this->createJpeg(1200, 900);

        $processor = $this->createStub(ImageProcessorInterface::class);
        $processor->method('resize')->willReturnOnConsecutiveCalls($thumb, $medium, $large);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg');

        self::assertCount(3, $variants);
    }

    #[Test]
    public function test_variant_path_includes_variant_name_and_format(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $configs = [
            new ImageVariantConfig('thumbnail', 150, 150, 'webp', 80),
        ];

        $resized = $this->createJpeg(150, 113);

        $processor = $this->createStub(ImageProcessorInterface::class);
        $processor->method('resize')->willReturn($resized);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);

        self::assertCount(1, $variants);
        self::assertSame('tenant/2026/02/ab/photo-thumbnail.webp', $variants[0]->path);
    }

    #[Test]
    public function test_format_conversion_passes_correct_format_to_processor(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $configs = [
            new ImageVariantConfig('thumb', 150, 150, 'avif', 60),
        ];

        $resized = $this->createJpeg(150, 113);

        $processor = $this->createMock(ImageProcessorInterface::class);
        $processor->expects(self::once())
            ->method('resize')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                'avif',
            )
            ->willReturn($resized);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);
    }

    #[Test]
    public function test_original_format_preserved_when_format_is_original(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $configs = [
            new ImageVariantConfig('thumb', 150, 150, 'original', 80),
        ];

        $resized = $this->createJpeg(150, 113);

        $processor = $this->createMock(ImageProcessorInterface::class);
        $processor->expects(self::once())
            ->method('resize')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                'jpeg', // Should detect original format from .jpg extension
            )
            ->willReturn($resized);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);

        self::assertCount(1, $variants);
        self::assertSame('jpeg', $variants[0]->format);
    }

    #[Test]
    public function test_returns_empty_for_invalid_source(): void
    {
        $invalidPath = $this->tmpDir . '/not_image.txt';
        file_put_contents($invalidPath, 'not an image');

        $configs = [
            new ImageVariantConfig('thumb', 150, 150, 'webp', 80),
        ];

        $processor = $this->createStub(ImageProcessorInterface::class);
        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($invalidPath, 'tenant/2026/02/ab/photo.jpg', $configs);

        self::assertCount(0, $variants);
    }

    #[Test]
    public function test_variant_records_correct_size_bytes(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $resized = $this->createJpeg(150, 113);
        $expectedSize = filesize($resized);

        $configs = [
            new ImageVariantConfig('thumb', 150, 150, 'jpeg', 80),
        ];

        $processor = $this->createStub(ImageProcessorInterface::class);
        $processor->method('resize')->willReturn($resized);

        $disk = $this->createStub(MediaDiskInterface::class);

        $generator = new ImageVariantGenerator($processor, $disk);
        $variants = $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);

        self::assertCount(1, $variants);
        self::assertSame($expectedSize, $variants[0]->sizeBytes);
    }

    #[Test]
    public function test_writes_variant_to_disk(): void
    {
        $source = $this->createJpeg(2000, 1500);
        $resized = $this->createJpeg(150, 113);

        $configs = [
            new ImageVariantConfig('thumb', 150, 150, 'jpeg', 80),
        ];

        $processor = $this->createStub(ImageProcessorInterface::class);
        $processor->method('resize')->willReturn($resized);

        $writtenPaths = [];
        $disk = $this->createMock(MediaDiskInterface::class);
        $disk->expects(self::once())
            ->method('write')
            ->with(
                'tenant/2026/02/ab/photo-thumb.jpeg',
                self::anything(),
            );

        $generator = new ImageVariantGenerator($processor, $disk);
        $generator->generate($source, 'tenant/2026/02/ab/photo.jpg', $configs);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        $color = (int) imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefill($img, 0, 0, $color);
        $path = $this->tmpDir . '/source_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($img, $path, 90);
        unset($img);
        $this->tempFiles[] = $path;

        return $path;
    }
}
