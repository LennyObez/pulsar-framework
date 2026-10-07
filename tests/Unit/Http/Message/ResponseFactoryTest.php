<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;

#[CoversClass(Response::class)]
final class ResponseFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $tempBase = realpath(sys_get_temp_dir());

        foreach ($this->tempFiles as $file) {
            $real = realpath($file);

            if ($real !== false && $tempBase !== false && str_starts_with($real, $tempBase) && is_file($real)) {
                self::assertFileExists($real); // assert before cleanup
                @unlink($real);
            }
        }

        $this->tempFiles = [];
    }

    #[Test]
    public function downloadSetsAttachmentDisposition(): void
    {
        $file = $this->createTempFile('test content');
        $response = Response::download($file, 'report.csv', 'text/csv');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(
            'attachment; filename="report.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('12', $response->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function downloadUsesBasenameWhenNoFilenameGiven(): void
    {
        $file = $this->createTempFile('data');
        $response = Response::download($file);
        $disposition = $response->getHeaderLine('Content-Disposition');

        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString(basename($file), $disposition);
    }

    #[Test]
    public function downloadThrowsForNonExistentFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        (void) Response::download('/nonexistent/file.txt');
    }

    #[Test]
    public function fileSetsInlineDisposition(): void
    {
        $file = $this->createTempFile('<svg></svg>');
        $response = Response::file($file, 'image/svg+xml');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(
            'inline',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    #[Test]
    public function fileThrowsForNonExistentFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) Response::file('/nonexistent/image.png');
    }

    #[Test]
    public function downloadSetsContentLength(): void
    {
        $content = str_repeat('x', 1024);
        $file = $this->createTempFile($content);
        $response = Response::download($file);

        self::assertSame('1024', $response->getHeaderLine('Content-Length'));
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar-test-');

        if ($path === false) {
            self::fail('Failed to create temp file');
        }

        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
