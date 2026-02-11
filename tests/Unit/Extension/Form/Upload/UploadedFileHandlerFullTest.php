<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Form\Config\UploadConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Contract\AntivirusScanResult;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Upload\FilenameSanitizer;
use Pulsar\Extension\Form\Upload\MimeSniffer;
use Pulsar\Extension\Form\Upload\UploadedFileHandler;
use Pulsar\Extension\Form\Upload\UploadResult;

#[CoversClass(UploadedFileHandler::class)]
#[CoversClass(UploadResult::class)]
final class UploadedFileHandlerFullTest extends TestCase
{
    private string $tmpDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_ufh_test_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tmpDir . '/storage';
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function createTmpFile(string $content = 'test content'): string
    {
        $path = $this->tmpDir . '/upload_' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, $content);

        return $path;
    }

    private function createHandler(
        ?int $maxSize = null,
        ?AntivirusPort $antivirus = null,
        ?LoggerInterface $logger = null,
    ): UploadedFileHandler {
        $config = new UploadConfig(
            directory: $this->storageDir,
            maxSize: $maxSize ?? 10_485_760,
            regulatedPreset: false,
        );

        return new UploadedFileHandler($config, new MimeSniffer(), new FilenameSanitizer(), $antivirus, $logger);
    }

    #[Test]
    public function handleSuccessfulUploadReturnsResult(): void
    {
        $tmpPath = $this->createTmpFile('hello world');
        $handler = $this->createHandler();

        $result = $handler->handle($tmpPath, 'document.txt', 'file_field');

        self::assertStringStartsWith($this->storageDir . '/', $result->storagePath);
        self::assertSame('document.txt', $result->originalName);
        self::assertSame('text/plain', $result->mimeType);
        self::assertSame(11, $result->size);
        self::assertFileExists($result->storagePath);
        self::assertFileDoesNotExist($tmpPath, 'Source file should be moved, not copied');
    }

    #[Test]
    public function handleCreatesStorageDirectoryWhenMissing(): void
    {
        $tmpPath = $this->createTmpFile('data');

        self::assertDirectoryDoesNotExist($this->storageDir);

        $this->createHandler()->handle($tmpPath, 'file.txt', 'upload');

        self::assertDirectoryExists($this->storageDir);
    }

    #[Test]
    public function handleThrowsForNonexistentFile(): void
    {
        $this->expectException(UploadException::class);

        $this->createHandler()->handle('/nonexistent/file.tmp', 'file.txt', 'upload');
    }

    #[Test]
    public function handleThrowsWhenFileSizeExceedsConfigMax(): void
    {
        $tmpPath = $this->createTmpFile(str_repeat('a', 200));

        $this->expectException(UploadException::class);

        $this->createHandler(maxSize: 100)->handle($tmpPath, 'big.txt', 'upload');
    }

    #[Test]
    public function handleUsesFieldMaxSizeOverrideInsteadOfConfig(): void
    {
        $tmpPath = $this->createTmpFile(str_repeat('a', 200));

        $this->expectException(UploadException::class);

        $this->createHandler(maxSize: 10_000)->handle($tmpPath, 'big.txt', 'upload', fieldMaxSize: 100);
    }

    #[Test]
    public function handleAllowsFileWithinFieldMaxSize(): void
    {
        $tmpPath = $this->createTmpFile(str_repeat('a', 50));

        $result = $this->createHandler(maxSize: 10)->handle($tmpPath, 'file.txt', 'upload', fieldMaxSize: 100);

        self::assertSame(50, $result->size);
    }

    #[Test]
    public function handleThrowsForDisallowedMimeType(): void
    {
        // Plain text content detected as text/plain by MimeSniffer
        $tmpPath = $this->createTmpFile('plain text data');

        $this->expectException(UploadException::class);

        $this->createHandler()->handle($tmpPath, 'file.txt', 'upload', allowedMimeTypes: ['image/png']);
    }

    #[Test]
    public function handleAcceptsMatchingMimeType(): void
    {
        $tmpPath = $this->createTmpFile('plain text data');

        $result = $this->createHandler()->handle($tmpPath, 'file.txt', 'upload', allowedMimeTypes: ['text/plain']);

        self::assertSame('text/plain', $result->mimeType);
    }

    #[Test]
    public function handleSkipsMimeCheckWhenAllowedListEmpty(): void
    {
        $tmpPath = $this->createTmpFile('plain text data');

        $result = $this->createHandler()->handle($tmpPath, 'file.txt', 'upload', allowedMimeTypes: []);

        self::assertNotEmpty($result->mimeType);
    }

    #[Test]
    public function handleThrowsOnAntivirusThreat(): void
    {
        $av = $this->createStub(AntivirusPort::class);
        $av->method('scan')->willReturn(AntivirusScanResult::infected('Trojan.Generic'));

        $tmpPath = $this->createTmpFile('malware payload');

        $this->expectException(UploadException::class);

        $this->createHandler(antivirus: $av)->handle($tmpPath, 'evil.exe', 'upload');
    }

    #[Test]
    public function handlePassesCleanAntivirusScan(): void
    {
        $av = $this->createStub(AntivirusPort::class);
        $av->method('scan')->willReturn(AntivirusScanResult::clean());

        $tmpPath = $this->createTmpFile('safe content');
        $result = $this->createHandler(antivirus: $av)->handle($tmpPath, 'safe.txt', 'upload');

        self::assertSame('safe.txt', $result->originalName);
    }

    #[Test]
    public function handleLogsWarningWhenNoAntivirusConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('No antivirus scanner configured'),
                self::callback(static fn(array $ctx): bool => $ctx['field'] === 'doc_field'
                    && $ctx['original_name'] === 'report.pdf'),
            );

        $tmpPath = $this->createTmpFile('content');
        $this->createHandler(logger: $logger)->handle($tmpPath, 'report.pdf', 'doc_field');
    }

    #[Test]
    public function handleSanitizesPathTraversalInFilename(): void
    {
        $tmpPath = $this->createTmpFile('data');
        $result = $this->createHandler()->handle($tmpPath, '../../../etc/passwd', 'upload');

        self::assertStringNotContainsString('..', $result->originalName);
        self::assertStringNotContainsString('/', $result->originalName);
    }

    #[Test]
    public function handleGeneratesUniqueStorageNames(): void
    {
        $tmpPath1 = $this->createTmpFile('data1');
        $tmpPath2 = $this->createTmpFile('data2');
        $handler = $this->createHandler();

        $result1 = $handler->handle($tmpPath1, 'same.txt', 'upload');
        $result2 = $handler->handle($tmpPath2, 'same.txt', 'upload');

        self::assertNotSame($result1->storageName, $result2->storageName);
    }

    #[Test]
    public function handleDoesNotLogWhenNoLoggerConfigured(): void
    {
        $tmpPath = $this->createTmpFile('content');
        $result = $this->createHandler(antivirus: null, logger: null)->handle($tmpPath, 'file.txt', 'upload');

        self::assertSame('file.txt', $result->originalName);
    }
}
