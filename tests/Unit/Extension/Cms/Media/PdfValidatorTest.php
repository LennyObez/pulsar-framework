<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;

#[CoversClass(PdfValidator::class)]
final class PdfValidatorTest extends TestCase
{
    private PdfValidator $validator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->validator = new PdfValidator();
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_pdf_test_' . bin2hex(random_bytes(4));
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

    // -- Valid PDF ---------------------------------------------------------

    #[Test]
    public function validPdfPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $path = $this->createPdf('%PDF-1.4 this is clean content without any dangerous patterns');

        $this->validator->validate($path);
    }

    #[Test]
    public function validPdfWithStandardObjectsPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $content = '%PDF-1.7' . "\n" .
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj' . "\n" .
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';

        $path = $this->createPdf($content);

        $this->validator->validate($path);
    }

    // -- Wrong magic bytes ------------------------------------------------

    #[Test]
    public function wrongMagicBytesRejected(): void
    {
        $path = $this->createPdf('NOT-A-PDF content here');

        $this->expectException(CmsException::class);
        $this->validator->validate($path);
    }

    #[Test]
    public function emptyFileRejected(): void
    {
        $path = $this->createPdf('');

        $this->expectException(CmsException::class);
        $this->validator->validate($path);
    }

    // -- Dangerous patterns -----------------------------------------------

    #[Test]
    #[DataProvider('dangerousPatternsProvider')]
    public function pdfWithDangerousPatternRejected(string $pattern, string $content): void
    {
        $path = $this->createPdf('%PDF-1.4 ' . $content);

        $this->expectException(CmsException::class);
        $this->validator->validate($path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerousPatternsProvider(): iterable
    {
        yield '/JavaScript' => ['/JavaScript', 'obj << /Type /Action /S /JavaScript /JS (alert) >> endobj'];
        yield '/JS' => ['/JS', 'obj << /S /JavaScript /JS (alert) >> endobj'];
        yield '/Launch' => ['/Launch', 'obj << /Type /Action /S /Launch /F (cmd.exe) >> endobj'];
        yield '/SubmitForm' => ['/SubmitForm', 'obj << /Type /Action /S /SubmitForm /F (http://evil.com) >> endobj'];
        yield '/GoToR' => ['/GoToR', 'obj << /Type /Action /S /GoToR /F (remote.pdf) >> endobj'];
    }

    // -- Clean PDF with no JS patterns passes -----------------------------

    #[Test]
    public function pdfWithNoJsPatternsPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $content = '%PDF-1.4' . "\n" .
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj' . "\n" .
            '2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj' . "\n" .
            'xref' . "\n" .
            'trailer << /Root 1 0 R >>' . "\n" .
            '%%EOF';

        $path = $this->createPdf($content);

        $this->validator->validate($path);
    }

    // -- Non-existent file ------------------------------------------------

    #[Test]
    public function nonExistentFileThrows(): void
    {
        $this->expectException(CmsException::class);
        $this->validator->validate($this->tmpDir . '/does-not-exist.pdf');
    }

    // -- Helper -----------------------------------------------------------

    private function createPdf(string $content): string
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, $content);

        return $path;
    }
}
