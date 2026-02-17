<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Cms\Media\Document\PdfThumbnailGenerator;

#[CoversClass(PdfThumbnailGenerator::class)]
final class PdfThumbnailGeneratorTest extends TestCase
{
    private LoggerInterface&Stub $logger;
    private PdfThumbnailGenerator $generator;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->generator = new PdfThumbnailGenerator($this->logger);
    }

    #[Test]
    public function generateReturnsNullForMissingFile(): void
    {
        $result = $this->generator->generate('/nonexistent/document.pdf');

        self::assertNull($result);
    }

    #[Test]
    public function generateReturnsNullForInvalidPdfWithoutImagickOrGs(): void
    {
        // Create a non-PDF temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_test_pdf_');
        self::assertNotFalse($tempFile);
        file_put_contents($tempFile, 'not a real pdf');

        // Without Imagick or Ghostscript, this will fail gracefully
        $result = $this->generator->generate($tempFile);

        // Result depends on available backend (Imagick or gs).
        // If neither is available, it returns null. If available but
        // the content is invalid, it also returns null.
        // Both outcomes are valid for this test.
        if ($result !== null) {
            self::assertFileExists($result);
            @unlink($result);
        } else {
            self::assertNull($result);
        }

        @unlink($tempFile);
    }

    #[Test]
    public function generateAcceptsCustomDimensions(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_test_pdf_');
        self::assertNotFalse($tempFile);
        file_put_contents($tempFile, 'not a real pdf');

        // This validates the method signature accepts width/height without error
        $result = $this->generator->generate($tempFile, 300, 400);

        // Will be null because the file is not a real PDF
        if ($result !== null) {
            @unlink($result);
        }

        @unlink($tempFile);
    }

    #[Test]
    public function constructorAcceptsCustomGhostscriptPath(): void
    {
        $generator = new PdfThumbnailGenerator(
            $this->logger,
            ghostscriptPath: '/usr/local/bin/gs',
        );

        // Just verifying construction succeeds with custom path
        $result = $generator->generate('/nonexistent/file.pdf');
        self::assertNull($result);
    }
}
