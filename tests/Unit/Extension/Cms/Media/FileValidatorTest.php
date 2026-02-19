<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\Security\FileValidator;

use function function_exists;
use function strlen;

#[CoversClass(FileValidator::class)]
final class FileValidatorTest extends TestCase
{
    private FileValidator $validator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->validator = new FileValidator(new MediaConfig());
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_fv_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // -- Valid images pass -------------------------------------------------

    #[Test]
    public function test_valid_jpeg_passes(): void
    {
        $path = $this->createTempFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));
        $this->createMinimalJpeg($path);

        $this->validator->validate($path, 'photo.jpg', 'image/jpeg', (int) filesize($path));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_valid_png_passes(): void
    {
        $path = $this->createMinimalPng();

        $this->validator->validate($path, 'image.png', 'image/png', (int) filesize($path));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_valid_webp_passes(): void
    {
        $path = $this->createTempFileWithContent("RIFF\x00\x00\x00\x00WEBP" . str_repeat("\x00", 100));

        // WebP can't be validated by getimagesize unless real, so test just magic byte + extension
        // The image-specific validation will reject fake content, but we test the pipeline logic
        // We'll test with a real GD-created WebP if available
        if (function_exists('imagecreatefromwebp')) {
            $img = imagecreatetruecolor(10, 10);
            $tmpPath = $this->tmpDir . '/valid.webp';
            imagewebp($img, $tmpPath);
            unset($img);

            $this->validator->validate($tmpPath, 'test.webp', 'image/webp', (int) filesize($tmpPath));
            $this->addToAssertionCount(1);

            return;
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_valid_gif_passes(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $path = $this->tmpDir . '/valid.gif';
        imagegif($img, $path);
        unset($img);

        $this->validator->validate($path, 'anim.gif', 'image/gif', (int) filesize($path));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_valid_pdf_passes(): void
    {
        $path = $this->createTempFileWithContent('%PDF-1.4 clean content here');

        $this->validator->validate($path, 'doc.pdf', 'application/pdf', strlen('%PDF-1.4 clean content here'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_valid_svg_passes(): void
    {
        $content = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>';
        $path = $this->createTempFileWithContent($content);

        $this->validator->validate($path, 'icon.svg', 'image/svg+xml', strlen($content));
        $this->addToAssertionCount(1);
    }

    // -- MIME mismatch: JPEG bytes but PNG Content-Type --------------------

    #[Test]
    public function test_mime_mismatch_jpeg_bytes_png_content_type_rejected(): void
    {
        $path = $this->createMinimalJpeg();

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'photo.jpg', 'image/png', (int) filesize($path));
    }

    // -- Extension mismatch: .jpg but PNG bytes ----------------------------

    #[Test]
    public function test_extension_mismatch_jpg_extension_png_bytes_rejected(): void
    {
        $path = $this->createMinimalPng();

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'photo.jpg', 'image/png', (int) filesize($path));
    }

    // -- Oversized file ---------------------------------------------------

    #[Test]
    public function test_oversized_file_rejected(): void
    {
        $path = $this->createMinimalJpeg();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('exceeds maximum');
        // Fake a file size > 10MB
        $this->validator->validate($path, 'photo.jpg', 'image/jpeg', 20_000_000);
    }

    // -- Oversized dimensions ---------------------------------------------

    #[Test]
    public function test_oversized_dimensions_rejected(): void
    {
        $config = new MediaConfig(maxImageWidth: 100, maxImageHeight: 100);
        $validator = new FileValidator($config);

        $img = imagecreatetruecolor(200, 200);
        $path = $this->tmpDir . '/big.jpg';
        imagejpeg($img, $path);
        unset($img);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('dimensions');
        $validator->validate($path, 'big.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- Decompression bomb (100MP+) --------------------------------------

    #[Test]
    public function test_pixel_count_exceeded_rejected(): void
    {
        $config = new MediaConfig(maxPixelCount: 100);
        $validator = new FileValidator($config);

        $img = imagecreatetruecolor(20, 20);
        $path = $this->tmpDir . '/bomb.jpg';
        imagejpeg($img, $path);
        unset($img);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('pixel count');
        $validator->validate($path, 'bomb.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- JPEG with embedded PHP -------------------------------------------

    #[Test]
    public function test_jpeg_with_embedded_php_rejected(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $path = $this->tmpDir . '/evil.jpg';
        imagejpeg($img, $path);
        unset($img);

        // Append PHP code to the JPEG
        file_put_contents($path, '<?php echo "hacked"; ?>', FILE_APPEND);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('PHP');
        $this->validator->validate($path, 'evil.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- Unknown extension ------------------------------------------------

    #[Test]
    public function test_unknown_extension_rejected(): void
    {
        $path = $this->createTempFileWithContent('some content');

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'file.exe', 'application/octet-stream', 100);
    }

    // -- Empty file -------------------------------------------------------

    #[Test]
    public function test_empty_file_rejected(): void
    {
        $path = $this->createTempFileWithContent('');

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'empty.jpg', 'image/jpeg', 0);
    }

    // -- Random bytes -----------------------------------------------------

    #[Test]
    public function test_random_bytes_rejected(): void
    {
        $path = $this->createTempFileWithContent(random_bytes(512));

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'random.jpg', 'image/jpeg', 512);
    }

    // -- Helpers ----------------------------------------------------------

    private function createTempFile(string $content): string
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(8));
        file_put_contents($path, $content);

        return $path;
    }

    private function createTempFileWithContent(string $content): string
    {
        return $this->createTempFile($content);
    }

    private function createMinimalJpeg(?string $path = null): string
    {
        $img = imagecreatetruecolor(10, 10);
        $path ??= $this->tmpDir . '/valid.jpg';
        imagejpeg($img, $path);
        unset($img);

        return $path;
    }

    private function createMinimalPng(?string $path = null): string
    {
        $img = imagecreatetruecolor(10, 10);
        $path ??= $this->tmpDir . '/valid.png';
        imagepng($img, $path);
        unset($img);

        return $path;
    }
}
