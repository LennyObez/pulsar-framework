<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\MimeSniffer;

use function is_resource;

/**
 * Tests MimeSniffer magic byte detection.
 *
 * Uses tmpfile() handles that PHP automatically cleans up when the resource
 * is garbage-collected, avoiding explicit unlink() calls.
 */
final class MimeSnifferTest extends TestCase
{
    private MimeSniffer $sniffer;

    /** @var list<resource> Keep handles alive until tearDown so the temp files persist for detection */
    private array $handles = [];

    protected function setUp(): void
    {
        $this->sniffer = new MimeSniffer();
    }

    protected function tearDown(): void
    {
        // Closing handles causes PHP to auto-delete the temp files
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->handles = [];
    }

    #[Test]
    public function detectJpeg(): void
    {
        $path = $this->writeTempFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12));

        self::assertSame('image/jpeg', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectPng(): void
    {
        $path = $this->writeTempFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 8));

        self::assertSame('image/png', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectGif87a(): void
    {
        $path = $this->writeTempFile('GIF87a' . str_repeat("\x00", 10));

        self::assertSame('image/gif', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectGif89a(): void
    {
        $path = $this->writeTempFile('GIF89a' . str_repeat("\x00", 10));

        self::assertSame('image/gif', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectPdf(): void
    {
        $path = $this->writeTempFile('%PDF-1.5' . str_repeat(' ', 8));

        self::assertSame('application/pdf', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectZip(): void
    {
        $path = $this->writeTempFile("PK\x03\x04" . str_repeat("\x00", 12));

        self::assertSame('application/zip', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectGzip(): void
    {
        $path = $this->writeTempFile("\x1F\x8B" . str_repeat("\x00", 14));

        self::assertSame('application/gzip', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectPlainText(): void
    {
        $path = $this->writeTempFile("Hello, this is plain text.\n");

        self::assertSame('text/plain', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectUnknownBinary(): void
    {
        $path = $this->writeTempFile("\x01\x02\x03\x04\x05\x06\x07\x08");

        self::assertSame('application/octet-stream', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectNonexistentFileReturnsOctetStream(): void
    {
        self::assertSame('application/octet-stream', $this->sniffer->detect('/nonexistent/file'));
    }

    #[Test]
    public function detectXml(): void
    {
        $path = $this->writeTempFile('<?xml version="1.0"?>');

        self::assertSame('application/xml', $this->sniffer->detect($path));
    }

    /**
     * Write content to a PHP-managed temp file and return its path.
     *
     * The resource handle is kept alive in $this->handles so the file
     * persists until tearDown calls fclose(), which triggers automatic
     * deletion by PHP (no explicit unlink needed).
     */
    private function writeTempFile(string $content): string
    {
        $handle = tmpfile();
        self::assertNotFalse($handle, 'tmpfile() failed');

        fwrite($handle, $content);
        fflush($handle);

        $meta = stream_get_meta_data($handle);
        $this->handles[] = $handle;

        return $meta['uri'];
    }
}
