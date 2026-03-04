<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\FileUploaderComponent;

#[CoversClass(FileUploaderComponent::class)]
final class FileUploaderComponentTest extends TestCase
{
    #[Test]
    public function renders_uploader(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->uploadUrl('/api/upload');

        $html = $uploader->render();

        self::assertStringContainsString('<pulsar-file-uploader', $html);
        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('role="button"', $html);
    }

    #[Test]
    public function tag_name(): void
    {
        self::assertSame('pulsar-file-uploader', new FileUploaderComponent()->tagName());
    }

    #[Test]
    public function multiple_attribute(): void
    {
        $uploader = new FileUploaderComponent();
        $html = $uploader->render();

        self::assertStringContainsString('multiple', $html);
    }

    #[Test]
    public function single_file(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->multiple(false);

        $html = $uploader->render();

        self::assertStringNotContainsString(' multiple', $html);
    }

    #[Test]
    public function accept_types(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->accept(['image/*', 'application/pdf']);

        $html = $uploader->render();

        self::assertStringContainsString('accept="image/*,application/pdf"', $html);
    }

    #[Test]
    public function max_file_size_shown(): void
    {
        $uploader = new FileUploaderComponent();
        $uploader->maxFileSizeMb(25);

        $html = $uploader->render();

        self::assertStringContainsString('Max 25MB', $html);
    }

    #[Test]
    public function progressbar_exists(): void
    {
        $uploader = new FileUploaderComponent();
        $html = $uploader->render();

        self::assertStringContainsString('role="progressbar"', $html);
    }

    #[Test]
    public function accessibility_label(): void
    {
        $uploader = new FileUploaderComponent();
        $html = $uploader->render();

        self::assertStringContainsString('aria-label="Drop files here or click to upload"', $html);
    }
}
