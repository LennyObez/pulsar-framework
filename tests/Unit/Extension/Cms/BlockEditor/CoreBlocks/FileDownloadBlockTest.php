<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersDownloadLink(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
        ]);

        self::assertStringContainsString('<div class="file-download">', $html);
        self::assertStringContainsString('href="/files/report.pdf"', $html);
        self::assertStringContainsString('download="report.pdf"', $html);
        self::assertStringContainsString('class="file-download__link"', $html);
        self::assertStringContainsString('>report.pdf</a>', $html);
        self::assertStringContainsString('</div>', $html);
    }

    #[Test]
    public function rendersWithDescription(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
            'description' => 'Annual financial report',
        ]);

        self::assertStringContainsString('<p class="file-download__description">Annual financial report</p>', $html);
    }

    #[Test]
    public function rendersWithFileSize(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
            'fileSize' => '2.4 MB',
        ]);

        self::assertStringContainsString('<span class="file-download__size">2.4 MB</span>', $html);
    }

    #[Test]
    public function omitsOptionalFieldsWhenNotProvided(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
        ]);

        self::assertStringNotContainsString('file-download__description', $html);
        self::assertStringNotContainsString('file-download__size', $html);
    }

    #[Test]
    public function escapesXssInFilename(): void
    {
        $html = $this->block->render([
            'url' => '/files/report.pdf',
            'filename' => '<script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInUrl(): void
    {
        $html = $this->block->render([
            'url' => '" onclick="alert(1)',
            'filename' => 'report.pdf',
        ]);

        self::assertStringContainsString('href="&quot;', $html);
    }

    #[Test]
    public function validatesRequiredUrl(): void
    {
        $errors = $this->block->validate(['filename' => 'report.pdf']);

        self::assertContains('url is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredFilename(): void
    {
        $errors = $this->block->validate(['url' => '/files/report.pdf']);

        self::assertContains('filename is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'url' => '/files/report.pdf',
            'filename' => 'report.pdf',
        ]);

        self::assertSame([], $errors);
    }
}
