<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\UploadConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Contract\AntivirusScanResult;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;
use Pulsar\Extension\Form\Upload\MimeSniffer;
use Pulsar\Extension\Form\Upload\UploadedFileHandler;
use Pulsar\Extension\Form\Upload\UploadResult;

#[CoversClass(MimeSniffer::class)]
#[CoversClass(FilenameSanitizer::class)]
#[CoversClass(UploadedFileHandler::class)]
#[CoversClass(UploadResult::class)]
#[CoversClass(AntivirusScanResult::class)]
final class UploadHandlerTest extends TestCase
{
    #[Test]
    public function filename_sanitizer_strips_path_traversal(): void
    {
        $sanitizer = new FilenameSanitizer();

        self::assertSame('file.txt', $sanitizer->sanitize('../../../file.txt'));
        self::assertSame('file.txt', $sanitizer->sanitize('..\\..\\file.txt'));
        // Forward slashes are stripped, leaving path components joined
        self::assertSame('pathtofile.txt', $sanitizer->sanitize('/path/to/file.txt'));
    }

    #[Test]
    public function filename_sanitizer_strips_null_bytes(): void
    {
        $sanitizer = new FilenameSanitizer();

        self::assertSame('file.txt', $sanitizer->sanitize("file\0.txt"));
    }

    #[Test]
    public function filename_sanitizer_strips_control_characters(): void
    {
        $sanitizer = new FilenameSanitizer();

        self::assertSame('file.txt', $sanitizer->sanitize("file\x01\x02.txt"));
    }

    #[Test]
    public function filename_sanitizer_generates_uuid_storage_names(): void
    {
        $sanitizer = new FilenameSanitizer();

        $name1 = $sanitizer->generateStorageName('photo.jpg');
        $name2 = $sanitizer->generateStorageName('photo.jpg');

        // UUID format with extension
        self::assertMatchesRegularExpression('/^[a-f0-9-]+\.jpg$/', $name1);
        self::assertNotSame($name1, $name2); // Each call generates unique name
    }

    #[Test]
    public function filename_sanitizer_preserves_extension(): void
    {
        $sanitizer = new FilenameSanitizer();

        $name = $sanitizer->generateStorageName('document.pdf');
        self::assertStringEndsWith('.pdf', $name);
    }

    #[Test]
    public function mime_sniffer_detects_png(): void
    {
        $sniffer = new MimeSniffer();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, "\x89PNG\r\n\x1A\n" . str_repeat('x', 100));

        try {
            self::assertSame('image/png', $sniffer->detect($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function mime_sniffer_detects_jpeg(): void
    {
        $sniffer = new MimeSniffer();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, "\xFF\xD8\xFF" . str_repeat('x', 100));

        try {
            self::assertSame('image/jpeg', $sniffer->detect($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function mime_sniffer_detects_pdf(): void
    {
        $sniffer = new MimeSniffer();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '%PDF-1.4' . str_repeat('x', 100));

        try {
            self::assertSame('application/pdf', $sniffer->detect($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function mime_sniffer_returns_octet_stream_for_unknown(): void
    {
        $sniffer = new MimeSniffer();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, "\x00\x01\x02\x03\x04\x05\x06\x07\x08");

        try {
            self::assertSame('application/octet-stream', $sniffer->detect($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function upload_handler_rejects_oversized_files(): void
    {
        $config = UploadConfig::fromArray(['max_size' => 100]);
        $handler = new UploadedFileHandler($config, new MimeSniffer(), new FilenameSanitizer());

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, str_repeat('x', 200));

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('exceeds maximum size');
            $handler->handle($tmpFile, 'big.txt', 'file');
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function upload_handler_rejects_wrong_mime_type(): void
    {
        $config = UploadConfig::fromArray(['max_size' => 10_000_000]);
        $handler = new UploadedFileHandler($config, new MimeSniffer(), new FilenameSanitizer());

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '%PDF-1.4' . str_repeat('x', 100));

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('MIME type');
            $handler->handle($tmpFile, 'fake.jpg', 'avatar', ['image/jpeg']);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function upload_handler_rejects_infected_files(): void
    {
        $config = UploadConfig::fromArray(['max_size' => 10_000_000]);
        $antivirus = new class implements AntivirusPort {
            public function scan(string $filePath): AntivirusScanResult
            {
                return AntivirusScanResult::infected('EICAR-Test-File');
            }
        };

        $handler = new UploadedFileHandler(
            $config,
            new MimeSniffer(),
            new FilenameSanitizer(),
            $antivirus,
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'test content');

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('Antivirus scan failed');
            $handler->handle($tmpFile, 'file.txt', 'file');
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function antivirus_scan_result_factory_methods(): void
    {
        $clean = AntivirusScanResult::clean();
        self::assertTrue($clean->clean);
        self::assertSame('', $clean->threat);

        $infected = AntivirusScanResult::infected('Trojan.Generic');
        self::assertFalse($infected->clean);
        self::assertSame('Trojan.Generic', $infected->threat);
    }
}
