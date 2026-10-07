<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Cms\Media\Document\DocumentMetadataExtractor;

#[CoversClass(DocumentMetadataExtractor::class)]
final class DocumentMetadataExtractorTest extends TestCase
{
    private LoggerInterface&Stub $logger;
    private DocumentMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->extractor = new DocumentMetadataExtractor($this->logger);
    }

    #[Test]
    public function extractReturnsEmptyMetadataForMissingFile(): void
    {
        $result = $this->extractor->extract('/nonexistent/document.pdf');

        self::assertNull($result->title);
        self::assertNull($result->pageCount);
        self::assertNull($result->pdfVersion);
    }

    #[Test]
    public function extractReturnsEmptyMetadataForNonPdfFile(): void
    {
        $tempFile = $this->createTempFileWithContent('This is not a PDF file');

        $result = $this->extractor->extract($tempFile);

        self::assertNull($result->title);
        self::assertNull($result->pdfVersion);

        @unlink($tempFile);
    }

    #[Test]
    public function extractParsesPdfVersion(): void
    {
        $content = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame('1.7', $result->pdfVersion);

        @unlink($tempFile);
    }

    #[Test]
    public function extractParsesMetadataFromInfoDictionary(): void
    {
        $content = "%PDF-1.5\n"
            . "1 0 obj\n"
            . "<<\n"
            . "/Type /Catalog\n"
            . ">>\n"
            . "endobj\n"
            . "2 0 obj\n"
            . "<<\n"
            . "/Title (Test Document)\n"
            . "/Author (Jane Smith)\n"
            . "/Subject (Unit Testing)\n"
            . "/Creator (PHPUnit)\n"
            . "/Producer (Pulsar Framework)\n"
            . "/CreationDate (D:20240115120000)\n"
            . "/ModDate (D:20240620153045)\n"
            . ">>\n"
            . "endobj\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame('1.5', $result->pdfVersion);
        self::assertSame('Test Document', $result->title);
        self::assertSame('Jane Smith', $result->author);
        self::assertSame('Unit Testing', $result->subject);
        self::assertSame('PHPUnit', $result->creator);
        self::assertSame('Pulsar Framework', $result->producer);
        self::assertSame('2024-01-15T12:00:00', $result->creationDate);
        self::assertSame('2024-06-20T15:30:45', $result->modificationDate);

        @unlink($tempFile);
    }

    #[Test]
    public function extractCountsPagesFromPagesCount(): void
    {
        $content = "%PDF-1.4\n"
            . "1 0 obj\n"
            . "<<\n"
            . "/Type /Pages\n"
            . "/Count 15\n"
            . "/Kids [2 0 R 3 0 R]\n"
            . ">>\n"
            . "endobj\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame(15, $result->pageCount);

        @unlink($tempFile);
    }

    #[Test]
    public function extractCountsPagesFromTypePageFallback(): void
    {
        $content = "%PDF-1.4\n"
            . "1 0 obj << /Type /Page >> endobj\n"
            . "2 0 obj << /Type /Page >> endobj\n"
            . "3 0 obj << /Type /Page >> endobj\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame(3, $result->pageCount);

        @unlink($tempFile);
    }

    #[Test]
    public function extractDoesNotCountTypePagesAsPage(): void
    {
        // /Type /Pages should not be counted as individual pages
        $content = "%PDF-1.4\n"
            . "1 0 obj << /Type /Pages /Count 2 >> endobj\n"
            . "2 0 obj << /Type /Page >> endobj\n"
            . "3 0 obj << /Type /Page >> endobj\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        // Should get 2 from /Pages /Count, not 2 from /Type /Page fallback
        self::assertSame(2, $result->pageCount);

        @unlink($tempFile);
    }

    #[Test]
    public function extractNormalizesDateWithoutDPrefix(): void
    {
        $content = "%PDF-1.4\n"
            . "/CreationDate (20240115)\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame('2024-01-15T00:00:00', $result->creationDate);

        @unlink($tempFile);
    }

    #[Test]
    public function extractReportsFileSize(): void
    {
        $content = "%PDF-1.4\n" . str_repeat('x', 1000);

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertNotNull($result->fileSize);
        self::assertGreaterThan(0, $result->fileSize);

        @unlink($tempFile);
    }

    #[Test]
    public function extractHandlesEscapedParenthesesInLiteralStrings(): void
    {
        $content = "%PDF-1.4\n"
            . "/Title (Document \\(Draft\\))\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame('Document (Draft)', $result->title);

        @unlink($tempFile);
    }

    #[Test]
    public function extractHandlesNestedParentheses(): void
    {
        $content = "%PDF-1.4\n"
            . "/Title (Document (v2))\n";

        $tempFile = $this->createTempFileWithContent($content);

        $result = $this->extractor->extract($tempFile);

        self::assertSame('Document (v2)', $result->title);

        @unlink($tempFile);
    }

    private function createTempFileWithContent(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_test_pdf_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }
}
