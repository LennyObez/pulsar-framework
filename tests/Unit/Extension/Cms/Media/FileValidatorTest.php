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
    public function validJpegPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $path = $this->createTempFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));
        $this->createMinimalJpeg($path);

        $this->validator->validate($path, 'photo.jpg', 'image/jpeg', (int) filesize($path));
    }

    #[Test]
    public function validPngPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $path = $this->createMinimalPng();

        $this->validator->validate($path, 'image.png', 'image/png', (int) filesize($path));
    }

    #[Test]
    public function validWebpPasses(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('WebP support not available via GD');
        }

        $this->expectNotToPerformAssertions();

        $img = imagecreatetruecolor(10, 10);
        $tmpPath = $this->tmpDir . '/valid.webp';
        imagewebp($img, $tmpPath);
        unset($img);

        $this->validator->validate($tmpPath, 'test.webp', 'image/webp', (int) filesize($tmpPath));
    }

    #[Test]
    public function validGifPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $img = imagecreatetruecolor(10, 10);
        $path = $this->tmpDir . '/valid.gif';
        imagegif($img, $path);
        unset($img);

        $this->validator->validate($path, 'anim.gif', 'image/gif', (int) filesize($path));
    }

    #[Test]
    public function validPdfPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $path = $this->createTempFileWithContent('%PDF-1.4 clean content here');

        $this->validator->validate($path, 'doc.pdf', 'application/pdf', strlen('%PDF-1.4 clean content here'));
    }

    #[Test]
    public function validSvgPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $content = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>';
        $path = $this->createTempFileWithContent($content);

        $this->validator->validate($path, 'icon.svg', 'image/svg+xml', strlen($content));
    }

    #[Test]
    public function validateReturnsTheCanonicalMimeRegardlessOfDeclaredCase(): void
    {
        // C5: a mixed-case declared Content-Type passes the case-insensitive
        // consistency check, but downstream dispatch must branch on the
        // canonical (lowercase, magic-byte-detected) MIME this returns — not the
        // declared one — or a `=== 'image/svg+xml'` check is bypassed and the
        // SVG is stored unsanitized (stored XSS).
        $content = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>';
        $path = $this->createTempFileWithContent($content);

        $detected = $this->validator->validate($path, 'icon.svg', 'Image/SVG+XML', strlen($content));

        self::assertSame('image/svg+xml', $detected);
    }

    // -- MIME mismatch: JPEG bytes but PNG Content-Type --------------------

    #[Test]
    public function mimeMismatchJpegBytesPngContentTypeRejected(): void
    {
        $path = $this->createMinimalJpeg();

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'photo.jpg', 'image/png', (int) filesize($path));
    }

    // -- Extension mismatch: .jpg but PNG bytes ----------------------------

    #[Test]
    public function extensionMismatchJpgExtensionPngBytesRejected(): void
    {
        $path = $this->createMinimalPng();

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'photo.jpg', 'image/png', (int) filesize($path));
    }

    // -- Oversized file ---------------------------------------------------

    #[Test]
    public function oversizedFileRejected(): void
    {
        $path = $this->createMinimalJpeg();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('exceeds maximum');
        // Fake a file size > 50MB (default maxUploadSize)
        $this->validator->validate($path, 'photo.jpg', 'image/jpeg', 60_000_000);
    }

    // -- Oversized dimensions ---------------------------------------------

    #[Test]
    public function oversizedDimensionsRejected(): void
    {
        $config = new MediaConfig(maxImageWidth: 100, maxImageHeight: 100);
        $validator = new FileValidator($config);

        $img = imagecreatetruecolor(200, 200);
        $path = $this->tmpDir . '/big.jpg';
        imagejpeg($img, $path);
        unset($img);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('dimensions');
        $validator->validate($path, 'big.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- Decompression bomb (100MP+) --------------------------------------

    #[Test]
    public function pixelCountExceededRejected(): void
    {
        $config = new MediaConfig(maxPixelCount: 100);
        $validator = new FileValidator($config);

        $img = imagecreatetruecolor(20, 20);
        $path = $this->tmpDir . '/bomb.jpg';
        imagejpeg($img, $path);
        unset($img);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('pixel count');
        $validator->validate($path, 'bomb.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- JPEG with embedded PHP -------------------------------------------

    #[Test]
    public function jpegWithEmbeddedPhpRejected(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $path = $this->tmpDir . '/evil.jpg';
        imagejpeg($img, $path);
        unset($img);

        // Append PHP code to the JPEG
        file_put_contents($path, '<?php echo "hacked"; ?>', FILE_APPEND);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('PHP');
        $this->validator->validate($path, 'evil.jpg', 'image/jpeg', (int) filesize($path));
    }

    // -- Unknown extension ------------------------------------------------

    #[Test]
    public function unknownExtensionRejected(): void
    {
        $path = $this->createTempFileWithContent('some content');

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'file.exe', 'application/octet-stream', 100);
    }

    // -- Empty file -------------------------------------------------------

    #[Test]
    public function emptyFileRejected(): void
    {
        $path = $this->createTempFileWithContent('');

        $this->expectException(CmsException::class);
        $this->validator->validate($path, 'empty.jpg', 'image/jpeg', 0);
    }

    // -- Random bytes -----------------------------------------------------

    #[Test]
    public function randomBytesRejected(): void
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
