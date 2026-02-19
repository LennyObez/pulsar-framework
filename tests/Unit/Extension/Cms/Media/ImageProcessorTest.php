<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageProcessor;

use function strlen;

#[CoversClass(ImageProcessor::class)]
final class ImageProcessorTest extends TestCase
{
    private ImageProcessor $processor;
    private string $tmpDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->processor = new ImageProcessor(new MediaConfig());
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_img_test_' . bin2hex(random_bytes(4));
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

    // ── Resize: aspect ratio preservation ────────────────────────────────

    #[Test]
    public function test_resize_preserves_aspect_ratio_when_height_is_null(): void
    {
        $source = $this->createJpeg(200, 100);

        $result = $this->processor->resize($source, 100, null, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(100, $info[0]); // width
        self::assertSame(50, $info[1]); // height (200:100 = 2:1, so 100/2 = 50)
    }

    #[Test]
    public function test_resize_with_explicit_dimensions(): void
    {
        $source = $this->createJpeg(200, 200);

        $result = $this->processor->resize($source, 50, 50, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(50, $info[0]);
        self::assertSame(50, $info[1]);
    }

    #[Test]
    public function test_resize_does_not_upscale(): void
    {
        $source = $this->createJpeg(100, 80);

        $result = $this->processor->resize($source, 200, null, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        // Should stay at original size, not upscale
        self::assertSame(100, $info[0]);
        self::assertSame(80, $info[1]);
    }

    #[Test]
    public function test_resize_tall_image_preserves_ratio(): void
    {
        $source = $this->createJpeg(100, 400);

        $result = $this->processor->resize($source, 50, null, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(50, $info[0]);
        self::assertSame(200, $info[1]); // 100:400 = 1:4, so 50*4 = 200
    }

    // ── Format detection / output ────────────────────────────────────────

    #[Test]
    public function test_resize_outputs_jpeg_format(): void
    {
        $source = $this->createJpeg(100, 100);

        $result = $this->processor->resize($source, 50, 50, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function test_resize_outputs_png_format(): void
    {
        $source = $this->createPng(100, 100);

        $result = $this->processor->resize($source, 50, 50, 'png');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    #[Test]
    public function test_resize_outputs_webp_format(): void
    {
        $source = $this->createJpeg(100, 100);

        $result = $this->processor->resize($source, 50, 50, 'webp');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_WEBP, $info[2]);
    }

    #[Test]
    public function test_resize_outputs_gif_format(): void
    {
        $source = $this->createGif(100, 100);

        $result = $this->processor->resize($source, 50, 50, 'gif');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_GIF, $info[2]);
    }

    #[Test]
    public function test_resize_from_png_to_jpeg_format_conversion(): void
    {
        $source = $this->createPng(100, 100);

        $result = $this->processor->resize($source, 50, 50, 'jpeg');
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function test_resize_unsupported_format_throws(): void
    {
        $source = $this->createJpeg(100, 100);

        $this->expectException(CmsException::class);
        $result = $this->processor->resize($source, 50, 50, 'bmp');
        $this->trackTempFile($result);
    }

    // ── EXIF extraction ──────────────────────────────────────────────────

    #[Test]
    public function test_extract_exif_returns_array_for_jpeg(): void
    {
        $source = $this->createJpeg(100, 100);

        $exif = $this->processor->extractExif($source);

        // GD-created JPEGs may or may not have EXIF depending on the runtime;
        // the contract is that it returns an array (never throws)
        self::assertIsArray($exif);
    }

    #[Test]
    public function test_extract_exif_returns_empty_for_png(): void
    {
        $source = $this->createPng(100, 100);

        $exif = $this->processor->extractExif($source);

        // PNG files don't have EXIF data
        self::assertSame([], $exif);
    }

    #[Test]
    public function test_extract_exif_returns_empty_for_invalid_file(): void
    {
        $path = $this->tmpDir . '/not_an_image.txt';
        file_put_contents($path, 'not an image');

        $exif = $this->processor->extractExif($path);

        self::assertSame([], $exif);
    }

    // ── EXIF stripping ───────────────────────────────────────────────────

    #[Test]
    public function test_strip_exif_returns_valid_jpeg(): void
    {
        $source = $this->createJpeg(100, 100);

        $result = $this->processor->stripExif($source);
        $this->trackTempFile($result);

        $info = getimagesize($result);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
        self::assertSame(100, $info[0]);
        self::assertSame(100, $info[1]);
    }

    // ── Blur placeholder generation ──────────────────────────────────────

    #[Test]
    public function test_blur_placeholder_returns_base64_data_uri(): void
    {
        $source = $this->createJpeg(200, 100);

        $result = $this->processor->generateBlurPlaceholder($source);

        self::assertStringStartsWith('data:image/webp;base64,', $result);
    }

    #[Test]
    public function test_blur_placeholder_is_valid_base64(): void
    {
        $source = $this->createJpeg(200, 100);

        $result = $this->processor->generateBlurPlaceholder($source);

        $base64Part = substr($result, strlen('data:image/webp;base64,'));
        $decoded = base64_decode($base64Part, true);
        self::assertNotFalse($decoded);
        self::assertNotEmpty($decoded);
    }

    #[Test]
    public function test_blur_placeholder_is_small(): void
    {
        $source = $this->createJpeg(1000, 600);

        $result = $this->processor->generateBlurPlaceholder($source);

        // The blur placeholder should be tiny (20px wide)
        // base64 output should be well under 5KB
        self::assertLessThan(5000, strlen($result));
    }

    // ── Invalid source throws ────────────────────────────────────────────

    #[Test]
    public function test_resize_invalid_source_throws(): void
    {
        $path = $this->tmpDir . '/bad.jpg';
        file_put_contents($path, 'not an image');

        $this->expectException(CmsException::class);
        $this->processor->resize($path, 50, 50, 'jpeg');
    }

    #[Test]
    public function test_blur_placeholder_invalid_source_throws(): void
    {
        $path = $this->tmpDir . '/bad.jpg';
        file_put_contents($path, 'not an image');

        $this->expectException(CmsException::class);
        $this->processor->generateBlurPlaceholder($path);
    }

    #[Test]
    public function test_resize_nonexistent_source_throws(): void
    {
        $this->expectException(CmsException::class);
        $this->processor->resize($this->tmpDir . '/nonexistent.jpg', 50, 50, 'jpeg');
    }

    // ── WebP quality configuration ───────────────────────────────────────

    #[Test]
    public function test_resize_respects_webp_quality_config(): void
    {
        $highQuality = new MediaConfig(webpQuality: 100);
        $lowQuality = new MediaConfig(webpQuality: 1);

        $processorHigh = new ImageProcessor($highQuality);
        $processorLow = new ImageProcessor($lowQuality);

        // Use a larger image with varied content so quality differences are visible
        $source = $this->createGradientJpeg(500, 500);

        $highResult = $processorHigh->resize($source, 400, 400, 'webp');
        $this->trackTempFile($highResult);
        $lowResult = $processorLow->resize($source, 400, 400, 'webp');
        $this->trackTempFile($lowResult);

        // Higher quality should produce a larger file
        self::assertGreaterThan(filesize($lowResult), filesize($highResult));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        $color = (int) imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefill($img, 0, 0, $color);
        $path = $this->tmpDir . '/source_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    private function createGradientJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x += 5) {
                $r = (int) (255 * $x / $width);
                $g = (int) (255 * $y / $height);
                $b = (int) (255 * (($x + $y) % $width) / $width);
                $color = (int) imagecolorallocate($img, $r, $g, $b);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $path = $this->tmpDir . '/gradient_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($img, $path, 95);
        imagedestroy($img);

        return $path;
    }

    private function createPng(int $width, int $height): string
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        $path = $this->tmpDir . '/source_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function createGif(int $width, int $height): string
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        $path = $this->tmpDir . '/source_' . bin2hex(random_bytes(4)) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function trackTempFile(string $path): void
    {
        $this->tempFiles[] = $path;
    }
}
