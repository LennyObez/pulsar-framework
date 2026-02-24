<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Media\ImageVariant;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaVisibility;
use Pulsar\Extension\Cms\Media\ResponsiveImageRenderer;

#[CoversClass(ResponsiveImageRenderer::class)]
final class ResponsiveImageRendererTest extends TestCase
{
    private ResponsiveImageRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ResponsiveImageRenderer();
    }

    #[Test]
    public function renderSimpleImgWhenNoVariants(): void
    {
        $asset = $this->buildImageAsset();

        $html = $this->renderer->render($asset);

        self::assertStringContainsString('<img ', $html);
        self::assertStringContainsString('src="storage/test.jpg"', $html);
        self::assertStringContainsString('alt="Test image"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringNotContainsString('<picture>', $html);
    }

    #[Test]
    public function renderSimpleImgForNonImage(): void
    {
        $asset = $this->buildAsset('application/pdf', 'doc.pdf');

        $variants = [
            new ImageVariant('/v/thumb.webp', 200, 150, 'webp', 5000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('<img ', $html);
        self::assertStringNotContainsString('<picture>', $html);
    }

    #[Test]
    public function renderPictureWithWebpVariants(): void
    {
        $asset = $this->buildImageAsset();
        $variants = [
            new ImageVariant('/v/thumb.webp', 200, 150, 'webp', 5000),
            new ImageVariant('/v/medium.webp', 800, 600, 'webp', 20000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('</picture>', $html);
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('/v/thumb.webp 200w', $html);
        self::assertStringContainsString('/v/medium.webp 800w', $html);
        self::assertStringContainsString('<img ', $html);
    }

    #[Test]
    public function renderPictureWithAvifVariants(): void
    {
        $asset = $this->buildImageAsset();
        $variants = [
            new ImageVariant('/v/thumb.avif', 200, 150, 'avif', 3000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('type="image/avif"', $html);
    }

    #[Test]
    public function renderPictureIncludesOriginalFormatSource(): void
    {
        $asset = $this->buildImageAsset();
        $variants = [
            new ImageVariant('/v/thumb.webp', 200, 150, 'webp', 5000),
            new ImageVariant('/v/thumb.jpg', 200, 150, 'jpeg', 8000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('type="image/jpeg"', $html);
    }

    #[Test]
    public function renderPictureIncludesWidthAndHeight(): void
    {
        $asset = $this->buildImageAsset(width: 1200, height: 800);
        $variants = [
            new ImageVariant('/v/thumb.webp', 200, 150, 'webp', 5000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('width="1200"', $html);
        self::assertStringContainsString('height="800"', $html);
    }

    #[Test]
    public function renderWithCustomAttributes(): void
    {
        $asset = $this->buildImageAsset();

        $html = $this->renderer->render($asset, [], ['class' => 'hero-image', 'loading' => 'eager']);

        self::assertStringContainsString('class="hero-image"', $html);
        self::assertStringContainsString('loading="eager"', $html);
    }

    #[Test]
    public function renderEscapesSpecialCharacters(): void
    {
        $now = new DateTimeImmutable();
        $asset = new MediaAsset(
            id: 'id-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: 'test<script>.jpg',
            storagePath: 'storage/test<script>.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 1024,
            fileHash: 'hash',
            width: null,
            height: null,
            exifData: null,
            altTextDefault: 'Alt "with" quotes & special <chars>',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $html = $this->renderer->render($asset);

        // htmlspecialchars converts < to &lt; and & to &amp;
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('alt=', $html);
        // The src should be escaped
        self::assertStringContainsString('src=', $html);
    }

    #[Test]
    public function renderPictureIncludesSizes(): void
    {
        $asset = $this->buildImageAsset();
        $variants = [
            new ImageVariant('/v/small.webp', 320, 240, 'webp', 3000),
            new ImageVariant('/v/large.webp', 1024, 768, 'webp', 15000),
        ];

        $html = $this->renderer->render($asset, $variants);

        self::assertStringContainsString('sizes="', $html);
        self::assertStringContainsString('(max-width: 640px) 100vw', $html);
    }

    private function buildImageAsset(?int $width = null, ?int $height = null): MediaAsset
    {
        return $this->buildAsset('image/jpeg', 'test.jpg', $width, $height);
    }

    private function buildAsset(string $mimeType, string $filename, ?int $width = null, ?int $height = null): MediaAsset
    {
        $now = new DateTimeImmutable();

        return new MediaAsset(
            id: 'asset-1',
            tenantId: null,
            uploaderId: 'user-1',
            filename: $filename,
            storagePath: 'storage/' . $filename,
            disk: 'local',
            mimeType: $mimeType,
            fileSize: 102400,
            fileHash: 'sha256:abc',
            width: $width,
            height: $height,
            exifData: null,
            altTextDefault: 'Test image',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }
}
