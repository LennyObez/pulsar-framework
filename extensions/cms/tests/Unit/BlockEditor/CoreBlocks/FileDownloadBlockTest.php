<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\FileDownloadBlock;

#[CoversClass(FileDownloadBlock::class)]
final class FileDownloadBlockTest extends TestCase
{
    private FileDownloadBlock $block;

    protected function setUp(): void
    {
        $this->block = new FileDownloadBlock();
    }

    #[Test]
    public function typeReturnsFileDownload(): void
    {
        self::assertSame('file-download', $this->block->type());
    }

    #[Test]
    public function renderOutputsDownloadLink(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
        ]);

        self::assertStringContainsString('href="/files/report.pdf"', $html);
        self::assertStringContainsString('download="report.pdf"', $html);
        self::assertStringContainsString('file-download__link', $html);
    }

    #[Test]
    public function renderIncludesDescriptionWhenPresent(): void
    {
        $html = $this->block->render([
            'url' => '/files/doc.pdf',
            'filename' => 'doc.pdf',
            'description' => 'Annual report 2025',
        ]);

        self::assertStringContainsString('file-download__description', $html);
        self::assertStringContainsString('Annual report 2025', $html);
    }

    #[Test]
    public function renderIncludesFileSizeWhenPresent(): void
    {
        $html = $this->block->render([
            'url' => '/files/doc.pdf',
            'filename' => 'doc.pdf',
            'fileSize' => '2.5 MB',
        ]);

        self::assertStringContainsString('file-download__size', $html);
        self::assertStringContainsString('2.5 MB', $html);
    }

    #[Test]
    public function renderOmitsOptionalFieldsWhenEmpty(): void
    {
        $html = $this->block->render([
            'url' => '/f.pdf',
            'filename' => 'f.pdf',
            'description' => '',
            'fileSize' => '',
        ]);

        self::assertStringNotContainsString('file-download__description', $html);
        self::assertStringNotContainsString('file-download__size', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingUrl(): void
    {
        $errors = $this->block->validate(['filename' => 'f.pdf']);

        self::assertStringContainsString('url is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingFilename(): void
    {
        $errors = $this->block->validate(['url' => '/f.pdf']);

        self::assertStringContainsString('filename is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'url' => '/file.zip',
            'filename' => 'file.zip',
        ]));
    }
}
