<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\FileUploaderComponent;

/**
 * Edge case tests for FileUploaderComponent.
 */
#[CoversClass(FileUploaderComponent::class)]
final class FileUploaderComponentEdgeTest extends TestCase
{
    #[Test]
    public function tagNameReturnsPulsarFileUploader(): void
    {
        $uploader = new FileUploaderComponent();

        self::assertSame('pulsar-file-uploader', $uploader->tagName());
    }

    #[Test]
    public function renderWithDefaults(): void
    {
        $uploader = new FileUploaderComponent();

        $html = $uploader->render();

        self::assertStringContainsString('pulsar-uploader-dropzone', $html);
        self::assertStringContainsString('Drag and drop', $html);
        self::assertStringContainsString('Max 10MB', $html);
        self::assertStringContainsString('multiple', $html);
        self::assertStringContainsString('role="progressbar"', $html);
    }

    #[Test]
    public function renderWithCustomMaxFileSize(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->maxFileSizeMb(25);

        $html = $uploader->render();

        self::assertStringContainsString('Max 25MB', $html);
    }

    #[Test]
    public function renderWithAcceptedTypes(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->accept(['image/png', 'image/jpeg']);

        $html = $uploader->render();

        self::assertStringContainsString('accept="image/png,image/jpeg"', $html);
    }

    #[Test]
    public function renderWithNoAcceptedTypes(): void
    {
        $uploader = new FileUploaderComponent();

        $html = $uploader->render();

        self::assertStringNotContainsString('accept=', $html);
    }

    #[Test]
    public function renderWithSingleFileMode(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->multiple(false);

        $html = $uploader->render();

        self::assertStringNotContainsString(' multiple', $html);
    }

    #[Test]
    public function renderWithUploadUrl(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->uploadUrl('/api/upload');

        $html = $uploader->render();

        self::assertStringContainsString('data-props=', $html);
        // The upload URL should be in the props JSON
        self::assertStringContainsString('/api/upload', html_entity_decode($html));
    }

    #[Test]
    public function renderSetsPropsInDataAttribute(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->uploadUrl('/upload');
        $uploader->maxFileSizeMb(5);
        $uploader->multiple(true);

        $html = $uploader->render();

        self::assertStringContainsString('data-props=', $html);
    }

    #[Test]
    public function renderWrapsInCustomElement(): void
    {
        $uploader = new FileUploaderComponent();

        $html = $uploader->render();

        self::assertStringStartsWith('<pulsar-file-uploader', $html);
        self::assertStringEndsWith('</pulsar-file-uploader>', $html);
    }

    #[Test]
    public function methodsReturnSelfForChaining(): void
    {
        $uploader = new FileUploaderComponent();

        $result = $uploader
            ->uploadUrl('/api/files')
            ->maxFileSizeMb(50)
            ->multiple(true)
            ->accept(['image/*']);

        self::assertInstanceOf(FileUploaderComponent::class, $result);
    }
}
