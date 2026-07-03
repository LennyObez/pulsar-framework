<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\UploadedFile;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

#[CoversClass(UploadedFile::class)]
#[CoversClass(Stream::class)]
final class UploadedFileTest extends TestCase
{
    #[Test]
    public function constructorWithStream(): void
    {
        $stream = Stream::create('file content');
        $uploaded = new UploadedFile($stream, 12, UPLOAD_ERR_OK, 'test.txt', 'text/plain');

        self::assertSame(12, $uploaded->getSize());
        self::assertSame(UPLOAD_ERR_OK, $uploaded->getError());
        self::assertSame('test.txt', $uploaded->getClientFilename());
        self::assertSame('text/plain', $uploaded->getClientMediaType());
    }

    #[Test]
    public function constructorWithFilePath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'file content');

        try {
            $uploaded = new UploadedFile($tmpFile, 12, UPLOAD_ERR_OK, 'test.txt', 'text/plain');

            self::assertSame(12, $uploaded->getSize());
            self::assertSame(UPLOAD_ERR_OK, $uploaded->getError());
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function constructorThrowsOnInvalidErrorCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UploadedFile(Stream::create(), null, 999);
    }

    #[Test]
    public function getStreamReturnsStreamInterface(): void
    {
        $stream = Stream::create('stream content');
        $uploaded = new UploadedFile($stream, 14, UPLOAD_ERR_OK);

        self::assertSame('stream content', (string) $uploaded->getStream());
    }

    #[Test]
    public function getStreamFromFilePath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'file data');

        try {
            $uploaded = new UploadedFile($tmpFile, 9, UPLOAD_ERR_OK);

            self::assertSame('file data', (string) $uploaded->getStream());
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function getStreamRejectsSapiUploadWithNonGenuinePath(): void
    {
        // FR-43: an upload received from the SAPI ($_FILES) whose backing path is
        // not a genuine PHP upload — a forged tmp_name attempting path traversal
        // or arbitrary-file read — must be refused. is_uploaded_file() is false
        // for any path outside a real HTTP upload (including this temp file in a
        // CLI test), so the guard fires; a programmatic upload (sapiUpload=false)
        // is exempt, as getStreamFromFilePath above shows.
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'sensitive data');

        try {
            $uploaded = new UploadedFile($tmpFile, 14, UPLOAD_ERR_OK, sapiUpload: true);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not a file uploaded via HTTP POST');

            (void) $uploaded->getStream();
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function moveToRejectsSapiUploadWithNonGenuinePath(): void
    {
        // FR-43: the same guard protects moveTo(), which for a genuine upload
        // uses move_uploaded_file(); a forged SAPI path is refused outright.
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'sensitive data');

        $target = tempnam(sys_get_temp_dir(), 'pulsar_target_');
        self::assertNotFalse($target);

        try {
            $uploaded = new UploadedFile($tmpFile, 14, UPLOAD_ERR_OK, sapiUpload: true);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not a file uploaded via HTTP POST');

            $uploaded->moveTo($target);
        } finally {
            @unlink($tmpFile);
            @unlink($target);
        }
    }

    #[Test]
    public function getStreamThrowsWhenMoved(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'content');

        $target = tempnam(sys_get_temp_dir(), 'pulsar_target_');
        self::assertNotFalse($target);

        try {
            $uploaded = new UploadedFile(
                Stream::create('content'),
                7,
                UPLOAD_ERR_OK,
            );
            $uploaded->moveTo($target);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already been moved');

            (void) $uploaded->getStream();
        } finally {
            @unlink($tmpFile);
            @unlink($target);
        }
    }

    #[Test]
    public function getStreamThrowsOnUploadError(): void
    {
        $uploaded = new UploadedFile(Stream::create(), null, UPLOAD_ERR_NO_FILE);

        $this->expectException(RuntimeException::class);

        (void) $uploaded->getStream();
    }

    #[Test]
    public function moveToWithStream(): void
    {
        $stream = Stream::create('moveable content');
        $uploaded = new UploadedFile($stream, 16, UPLOAD_ERR_OK);

        $target = tempnam(sys_get_temp_dir(), 'pulsar_move_');
        self::assertNotFalse($target);

        try {
            $uploaded->moveTo($target);

            self::assertSame('moveable content', file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }

    #[Test]
    public function moveToWithFilePath(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'pulsar_src_');
        self::assertNotFalse($source);
        file_put_contents($source, 'source content');

        $target = tempnam(sys_get_temp_dir(), 'pulsar_dst_');
        self::assertNotFalse($target);

        try {
            $uploaded = new UploadedFile($source, 14, UPLOAD_ERR_OK);
            $uploaded->moveTo($target);

            self::assertSame('source content', file_get_contents($target));
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    #[Test]
    public function moveToThrowsOnSecondCall(): void
    {
        $stream = Stream::create('data');
        $uploaded = new UploadedFile($stream, 4, UPLOAD_ERR_OK);

        $target1 = tempnam(sys_get_temp_dir(), 'pulsar_move1_');
        self::assertNotFalse($target1);
        $target2 = tempnam(sys_get_temp_dir(), 'pulsar_move2_');
        self::assertNotFalse($target2);

        try {
            $uploaded->moveTo($target1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already been moved');

            $uploaded->moveTo($target2);
        } finally {
            @unlink($target1);
            @unlink($target2);
        }
    }

    #[Test]
    public function moveToThrowsOnEmptyTargetPath(): void
    {
        $uploaded = new UploadedFile(Stream::create('data'), 4, UPLOAD_ERR_OK);

        $this->expectException(InvalidArgumentException::class);

        $uploaded->moveTo('');
    }

    #[Test]
    public function moveToThrowsOnUploadError(): void
    {
        $uploaded = new UploadedFile(Stream::create(), null, UPLOAD_ERR_CANT_WRITE);

        $target = tempnam(sys_get_temp_dir(), 'pulsar_err_');
        self::assertNotFalse($target);

        try {
            $this->expectException(RuntimeException::class);

            $uploaded->moveTo($target);
        } finally {
            @unlink($target);
        }
    }

    #[Test]
    public function getSizeReturnsNull(): void
    {
        $uploaded = new UploadedFile(Stream::create(), null, UPLOAD_ERR_OK);

        self::assertNull($uploaded->getSize());
    }

    #[Test]
    public function getClientFilenameReturnsNull(): void
    {
        $uploaded = new UploadedFile(Stream::create(), null, UPLOAD_ERR_OK);

        self::assertNull($uploaded->getClientFilename());
    }

    #[Test]
    public function getClientMediaTypeReturnsNull(): void
    {
        $uploaded = new UploadedFile(Stream::create(), null, UPLOAD_ERR_OK);

        self::assertNull($uploaded->getClientMediaType());
    }
}
