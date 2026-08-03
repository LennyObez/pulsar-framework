<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\UploadedFile;
use RuntimeException;

use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_OK;

#[CoversClass(UploadedFile::class)]
final class UploadedFileCoverageTest extends TestCase
{
    #[Test]
    public function moveToThrowsOnNonWritableDirectory(): void
    {
        $uploaded = new UploadedFile(Stream::create('data'), 4, UPLOAD_ERR_OK);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not exist or is not writable');

        $uploaded->moveTo('/nonexistent/directory/file.txt');
    }

    #[Test]
    public function moveToWithFilePathThatCannotBeRenamed(): void
    {
        // Create a file path that exists as the source
        $source = tempnam(sys_get_temp_dir(), 'pulsar_src_');
        self::assertNotFalse($source);
        file_put_contents($source, 'rename-test');

        $target = tempnam(sys_get_temp_dir(), 'pulsar_dst_');
        self::assertNotFalse($target);

        try {
            $uploaded = new UploadedFile($source, 11, UPLOAD_ERR_OK);
            $uploaded->moveTo($target);

            self::assertSame('rename-test', file_get_contents($target));
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    #[Test]
    public function moveToStreamCopiesContent(): void
    {
        $stream = Stream::create('stream-content');
        $uploaded = new UploadedFile($stream, 14, UPLOAD_ERR_OK);

        $target = tempnam(sys_get_temp_dir(), 'pulsar_mv_');
        self::assertNotFalse($target);

        try {
            $uploaded->moveTo($target);

            self::assertSame('stream-content', file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }

    #[Test]
    public function getStreamReturnsStreamForFilePath(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'pulsar_get_');
        self::assertNotFalse($source);
        file_put_contents($source, 'file-content');

        try {
            $uploaded = new UploadedFile($source, 12, UPLOAD_ERR_OK);
            $stream = $uploaded->getStream();

            self::assertSame('file-content', (string) $stream);
        } finally {
            @unlink($source);
        }
    }
}
