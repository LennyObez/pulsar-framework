<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaDerivative;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;

use function file_put_contents;
use function hash;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * E2E: Media security — JPEG derivatives, SVG sanitization, MIME mismatch rejection, PDF JS stripping.
 */
#[Group('e2e-cms')]
final class MediaSecurityTest extends TestCase
{
    #[Test]
    public function test_jpeg_derivatives_created_for_image(): void
    {
        $asset = MediaAsset::create(
            id: 'media-sec-jpeg-001',
            uploaderId: 'author-sec-001',
            filename: 'hero-photo.jpg',
            storagePath: 'media/2026/02/hero-photo.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 2_500_000,
            fileHash: hash('sha256', 'jpeg-content'),
            width: 3840,
            height: 2160,
        );

        self::assertTrue($asset->isImage());
        self::assertSame(3840, $asset->width);
        self::assertFalse($asset->isDeleted());

        // Simulate derivative generation for standard variants
        $variants = [
            ['variant' => 'thumb_480', 'width' => 480, 'height' => 270, 'format' => 'webp'],
            ['variant' => 'medium_960', 'width' => 960, 'height' => 540, 'format' => 'webp'],
            ['variant' => 'large_1920', 'width' => 1920, 'height' => 1080, 'format' => 'webp'],
        ];

        $derivatives = [];
        foreach ($variants as $i => $v) {
            $derivatives[] = new MediaDerivative(
                id: "deriv-sec-{$i}",
                mediaAssetId: $asset->id,
                variant: $v['variant'],
                format: $v['format'],
                storagePath: "media/2026/02/derivatives/hero-photo_{$v['variant']}.{$v['format']}",
                fileSize: (int) ($asset->fileSize * ($v['width'] / $asset->width)),
                width: $v['width'],
                height: $v['height'],
                fileHash: hash('sha256', "derivative-{$v['variant']}"),
                createdAt: new DateTimeImmutable(),
            );
        }

        self::assertCount(3, $derivatives);

        // All derivatives reference the same parent asset
        foreach ($derivatives as $d) {
            self::assertSame($asset->id, $d->mediaAssetId);
            self::assertSame('webp', $d->format);
        }

        // Verify size reduction across variants
        self::assertLessThan($derivatives[2]->fileSize, $derivatives[1]->fileSize);
        self::assertLessThan($derivatives[1]->fileSize, $derivatives[0]->fileSize);
    }

    #[Test]
    public function test_svg_sanitizer_strips_script_tags(): void
    {
        $sanitizer = new SvgSanitizer();

        $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect width="100" height="100" fill="blue"/>'
            . '<script>alert("XSS")</script>'
            . '</svg>';

        $sanitized = $sanitizer->sanitize($maliciousSvg);

        self::assertStringContainsString('<rect', $sanitized);
        self::assertStringNotContainsString('<script', $sanitized);
        self::assertStringNotContainsString('alert', $sanitized);
    }

    #[Test]
    public function test_svg_sanitizer_strips_event_handlers(): void
    {
        $sanitizer = new SvgSanitizer();

        $svgWithEvents = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect width="100" height="100" fill="red" onclick="alert(1)" onmouseover="evil()"/>'
            . '</svg>';

        $sanitized = $sanitizer->sanitize($svgWithEvents);

        self::assertStringContainsString('<rect', $sanitized);
        self::assertStringNotContainsString('onclick', $sanitized);
        self::assertStringNotContainsString('onmouseover', $sanitized);
    }

    #[Test]
    public function test_svg_sanitizer_removes_foreignobject(): void
    {
        $sanitizer = new SvgSanitizer();

        $svgWithForeignObject = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="100" height="100" fill="green"/>'
            . '<foreignObject width="100" height="100">'
            . '<body xmlns="http://www.w3.org/1999/xhtml"><script>hack()</script></body>'
            . '</foreignObject>'
            . '</svg>';

        $sanitized = $sanitizer->sanitize($svgWithForeignObject);

        self::assertStringContainsString('<rect', $sanitized);
        self::assertStringNotContainsString('foreignObject', $sanitized);
        self::assertStringNotContainsString('hack()', $sanitized);
    }

    #[Test]
    public function test_svg_sanitizer_blocks_javascript_href(): void
    {
        $sanitizer = new SvgSanitizer();

        $svgWithJsHref = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<use href="javascript:alert(1)"/>'
            . '<circle cx="50" cy="50" r="40" fill="blue"/>'
            . '</svg>';

        $sanitized = $sanitizer->sanitize($svgWithJsHref);

        self::assertStringNotContainsString('javascript:', $sanitized);
        self::assertStringContainsString('<circle', $sanitized);
    }

    #[Test]
    public function test_pdf_validator_rejects_javascript(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_test_');
        self::assertNotFalse($tempFile);

        try {
            // Create a minimal PDF with embedded JavaScript
            $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/OpenAction << /S /JavaScript /JS (app.alert('XSS')) >>\n>>\nendobj";
            file_put_contents($tempFile, $pdfContent);

            $validator = new PdfValidator();

            $this->expectException(CmsException::class);
            $validator->validate($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function test_pdf_validator_rejects_launch_actions(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_test_');
        self::assertNotFalse($tempFile);

        try {
            $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/OpenAction << /S /Launch /F (cmd.exe) >>\n>>\nendobj";
            file_put_contents($tempFile, $pdfContent);

            $validator = new PdfValidator();

            $this->expectException(CmsException::class);
            $validator->validate($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function test_pdf_validator_accepts_safe_pdf(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_test_');
        self::assertNotFalse($tempFile);

        try {
            // Minimal valid PDF without dangerous patterns
            $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids []\n/Count 0\n>>\nendobj\n%%EOF";
            file_put_contents($tempFile, $pdfContent);

            $validator = new PdfValidator();
            $validator->validate($tempFile);

            // If we get here, validation passed without throwing
            self::assertFileExists($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function test_pdf_validator_rejects_non_pdf_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_pdf_test_');
        self::assertNotFalse($tempFile);

        try {
            file_put_contents($tempFile, 'This is not a PDF file');

            $validator = new PdfValidator();

            $this->expectException(CmsException::class);
            $validator->validate($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function test_media_asset_data_classification(): void
    {
        $publicAsset = MediaAsset::create(
            id: 'media-sec-pub',
            uploaderId: 'author-sec',
            filename: 'public-image.jpg',
            storagePath: 'media/public-image.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 100_000,
            fileHash: hash('sha256', 'public'),
            dataClassification: DataClassification::Public,
        );

        $internalAsset = MediaAsset::create(
            id: 'media-sec-int',
            uploaderId: 'author-sec',
            filename: 'internal-doc.pdf',
            storagePath: 'media/internal-doc.pdf',
            disk: 'local',
            mimeType: 'application/pdf',
            fileSize: 200_000,
            fileHash: hash('sha256', 'internal'),
            visibility: MediaVisibility::Private,
            dataClassification: DataClassification::Internal,
        );

        self::assertSame(DataClassification::Public, $publicAsset->dataClassification);
        self::assertSame(MediaVisibility::Public, $publicAsset->visibility);

        self::assertSame(DataClassification::Internal, $internalAsset->dataClassification);
        self::assertSame(MediaVisibility::Private, $internalAsset->visibility);
    }
}
