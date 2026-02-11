<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\MimeSniffer;

#[CoversClass(MimeSniffer::class)]
final class MimeSnifferTest extends TestCase
{
    private MimeSniffer $sniffer;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->sniffer = new MimeSniffer();
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_mime_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    #[Test]
    public function detectsJpeg(): void
    {
        $path = $this->writeFile('test.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12));
        self::assertSame('image/jpeg', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsPng(): void
    {
        $path = $this->writeFile('test.png', "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 8));
        self::assertSame('image/png', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsGif87a(): void
    {
        $path = $this->writeFile('test.gif', 'GIF87a' . str_repeat("\x00", 10));
        self::assertSame('image/gif', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsGif89a(): void
    {
        $path = $this->writeFile('test.gif', 'GIF89a' . str_repeat("\x00", 10));
        self::assertSame('image/gif', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsPdf(): void
    {
        $path = $this->writeFile('test.pdf', '%PDF-1.4' . str_repeat("\x00", 8));
        self::assertSame('application/pdf', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsZip(): void
    {
        $path = $this->writeFile('test.zip', "PK\x03\x04" . str_repeat("\x00", 12));
        self::assertSame('application/zip', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsGzip(): void
    {
        $path = $this->writeFile('test.gz', "\x1F\x8B" . str_repeat("\x00", 14));
        self::assertSame('application/gzip', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsXml(): void
    {
        $path = $this->writeFile('test.xml', '<?xml version="1.0"?>');
        self::assertSame('application/xml', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsPlainText(): void
    {
        $path = $this->writeFile('test.txt', "Hello, World!\nThis is plain text.\n");
        self::assertSame('text/plain', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsOctetStreamForBinaryWithControlChars(): void
    {
        // Binary content with control chars that's not a known signature
        $path = $this->writeFile('test.bin', "\x07\x08\x0B\x0C\x0E\x0F" . str_repeat("\x00", 10));
        self::assertSame('application/octet-stream', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsRiffWebp(): void
    {
        $path = $this->writeFile('test.webp', "RIFF\x00\x00\x00\x00WEBP" . str_repeat("\x00", 4));
        self::assertSame('image/webp', $this->sniffer->detect($path));
    }

    #[Test]
    public function riffNonWebpFallsThrough(): void
    {
        // RIFF but not WEBP — should not detect as image/webp
        $path = $this->writeFile('test.avi', "RIFF\x00\x00\x00\x00AVI " . str_repeat("\x00", 4));
        // Falls through to text/plain or octet-stream depending on remaining content
        $mime = $this->sniffer->detect($path);
        self::assertNotSame('image/webp', $mime);
    }

    #[Test]
    public function detectsMp3WithId3Tag(): void
    {
        $path = $this->writeFile('test.mp3', "\x49\x44\x33" . str_repeat("\x00", 13));
        self::assertSame('audio/mpeg', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsFlac(): void
    {
        $path = $this->writeFile('test.flac', 'fLaC' . str_repeat("\x00", 12));
        self::assertSame('audio/flac', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsWebm(): void
    {
        $path = $this->writeFile('test.webm', "\x1A\x45\xDF\xA3" . str_repeat("\x00", 12));
        self::assertSame('video/webm', $this->sniffer->detect($path));
    }

    #[Test]
    public function detectsIco(): void
    {
        $path = $this->writeFile('test.ico', "\x00\x00\x01\x00" . str_repeat("\x00", 12));
        self::assertSame('image/x-icon', $this->sniffer->detect($path));
    }

    #[Test]
    public function returnsOctetStreamForNonexistentFile(): void
    {
        self::assertSame('application/octet-stream', @$this->sniffer->detect('/nonexistent/file'));
    }
}
