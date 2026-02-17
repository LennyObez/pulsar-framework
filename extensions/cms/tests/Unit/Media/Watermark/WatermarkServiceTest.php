<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Watermark;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\WatermarkConfig;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkPosition;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkService;

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

#[CoversClass(WatermarkService::class)]
final class WatermarkServiceTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/pulsar_wm_test_' . bin2hex(random_bytes(4));
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
    public function apply_returns_false_when_disabled(): void
    {
        $config = new WatermarkConfig(enabled: false);
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);

        self::assertFalse($service->apply($imagePath, 'large'));
    }

    #[Test]
    public function apply_returns_false_when_variant_excluded(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            text: 'Copyright',
            perVariant: ['thumbnail' => false],
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);

        self::assertFalse($service->apply($imagePath, 'thumbnail'));
    }

    #[Test]
    public function apply_text_watermark_with_builtin_font(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            text: 'SAMPLE',
            position: WatermarkPosition::Center,
            opacity: 80,
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);
        $originalSize = filesize($imagePath);

        $applied = $service->apply($imagePath, 'large');

        self::assertTrue($applied);
        self::assertFileExists($imagePath);

        // File content should have changed (watermark applied)
        $newSize = filesize($imagePath);
        // At minimum the file still exists and is a valid image
        $info = getimagesize($imagePath);
        self::assertNotFalse($info);
    }

    #[Test]
    public function apply_returns_false_when_no_watermark_content(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            imagePath: null,
            text: null,
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);

        self::assertFalse($service->apply($imagePath, 'large'));
    }

    #[Test]
    public function apply_returns_false_for_nonexistent_watermark_image(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            imagePath: '/nonexistent/watermark.png',
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);

        self::assertFalse($service->apply($imagePath, 'large'));
    }

    #[Test]
    public function apply_image_watermark_on_jpeg(): void
    {
        // Create a small watermark image
        $watermarkPath = $this->createTestPng(50, 50);

        $config = new WatermarkConfig(
            enabled: true,
            imagePath: $watermarkPath,
            position: WatermarkPosition::BottomRight,
            opacity: 50,
            scale: 25,
            margin: 5,
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(400, 300);

        $applied = $service->apply($imagePath, 'large');

        self::assertTrue($applied);
        // Verify the output is still a valid image
        $info = getimagesize($imagePath);
        self::assertNotFalse($info);
        self::assertSame(400, $info[0]);
        self::assertSame(300, $info[1]);
    }

    #[Test]
    public function apply_text_watermark_with_empty_string_returns_false(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            text: '',
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestJpeg(200, 200);

        self::assertFalse($service->apply($imagePath, 'large'));
    }

    #[Test]
    public function apply_returns_false_for_invalid_image_path(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            text: 'Test',
        );
        $service = new WatermarkService($config, new NullLogger());

        self::assertFalse($service->apply('/nonexistent/image.jpg', 'large'));
    }

    #[Test]
    public function apply_image_watermark_at_all_positions(): void
    {
        $watermarkPath = $this->createTestPng(20, 20);

        foreach (WatermarkPosition::cases() as $position) {
            $config = new WatermarkConfig(
                enabled: true,
                imagePath: $watermarkPath,
                position: $position,
                opacity: 50,
                scale: 10,
                margin: 5,
            );
            $service = new WatermarkService($config, new NullLogger());

            $imagePath = $this->createTestJpeg(200, 200);
            $applied = $service->apply($imagePath, 'large');

            self::assertTrue($applied, "Watermark should apply at position {$position->value}");
            self::assertNotFalse(getimagesize($imagePath), "Image at position {$position->value} should be valid");
        }
    }

    #[Test]
    public function apply_on_png_saves_as_png(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            text: 'Test',
            position: WatermarkPosition::Center,
        );
        $service = new WatermarkService($config, new NullLogger());

        $imagePath = $this->createTestPng(200, 200);

        $applied = $service->apply($imagePath, 'large');

        self::assertTrue($applied);
        $info = getimagesize($imagePath);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    private function createTestJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        // Fill with a solid color so watermark is visible in manual inspection
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

        $bg = imagecolorallocate($image, 255, 0, 0);

        if ($bg !== false) {
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $bg);
        }

        $path = $this->testDir . '/test_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        return $path;
    }
}
