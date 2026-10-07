<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use NoDiscard;
use Override;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Pulsar\Api\Api;
use RuntimeException;

use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function is_dir;
use function is_string;
use function is_uploaded_file;
use function is_writable;
use function move_uploaded_file;
use function rename;
use function sprintf;
use function stream_copy_to_stream;

use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_EXTENSION;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_NO_TMP_DIR;
use const UPLOAD_ERR_OK;
use const UPLOAD_ERR_PARTIAL;

/**
 * PSR-7 uploaded file value object.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class UploadedFile implements UploadedFileInterface
{
    private const array VALID_ERROR_CODES = [
        UPLOAD_ERR_OK,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE,
        UPLOAD_ERR_PARTIAL,
        UPLOAD_ERR_NO_FILE,
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION,
    ];

    private ?StreamInterface $stream = null;

    private ?string $file = null;

    private bool $moved = false;

    public function __construct(
        StreamInterface|string $streamOrFile,
        private readonly ?int $size,
        private readonly int $error,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
        private readonly bool $sapiUpload = false,
    ) {
        if (!in_array($error, self::VALID_ERROR_CODES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid upload error code: %d', $error));
        }

        if (is_string($streamOrFile)) {
            $this->file = $streamOrFile;
        } else {
            $this->stream = $streamOrFile;
        }
    }

    #[Override]
    #[NoDiscard]
    public function getStream(): StreamInterface
    {
        $this->assertNotMoved();
        $this->assertUploadSuccess();

        if ($this->stream !== null) {
            return $this->stream;
        }

        if ($this->file !== null) {
            $file = $this->file;
            $this->assertGenuineUpload();
            $this->stream = Stream::fromFile($file);

            return $this->stream;
        }

        throw new RuntimeException('No stream or file available');
    }

    #[Override]
    public function moveTo(string $targetPath): void
    {
        $this->assertNotMoved();
        $this->assertUploadSuccess();

        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path must not be empty');
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new RuntimeException(sprintf('Target directory "%s" does not exist or is not writable', $targetDir));
        }

        if ($this->file !== null) {
            $this->moveFile($this->file, $targetPath);
        } else {
            $this->copyStreamTo($targetPath);
        }

        $this->moved = true;
    }

    /**
     * Move a file-backed upload to its destination.
     *
     * A genuine SAPI upload is relocated with move_uploaded_file(), which
     * re-validates is_uploaded_file() and is the only safe primitive for the
     * PHP upload temp file. A programmatic source (factory, tests) uses a plain
     * rename with a stream-copy backstop for cross-filesystem moves.
     */
    private function moveFile(string $source, string $targetPath): void
    {
        if ($this->sapiUpload) {
            $this->assertGenuineUpload();

            if (!@move_uploaded_file($source, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to move uploaded file to "%s"', $targetPath));
            }

            return;
        }

        if (!@rename($source, $targetPath)) {
            $this->copyStreamTo($targetPath);
        }
    }

    /**
     * Reject access to a SAPI-sourced upload whose backing path is not a genuine
     * PHP upload — a forged $_FILES tmp_name attempting path traversal or an
     * arbitrary-file read. The guard applies only to uploads received from the
     * SAPI; programmatically constructed UploadedFiles carry trusted paths and
     * are exempt (is_uploaded_file() is false for every path outside a request).
     */
    private function assertGenuineUpload(): void
    {
        if ($this->sapiUpload && $this->file !== null && !is_uploaded_file($this->file)) {
            throw new RuntimeException(
                sprintf('Refusing to access "%s": not a file uploaded via HTTP POST', $this->file),
            );
        }
    }

    #[Override]
    #[NoDiscard]
    public function getSize(): ?int
    {
        return $this->size;
    }

    #[Override]
    #[NoDiscard]
    public function getError(): int
    {
        return $this->error;
    }

    #[Override]
    #[NoDiscard]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[Override]
    #[NoDiscard]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertNotMoved(): void
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved');
        }
    }

    private function assertUploadSuccess(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('Upload error: %d', $this->error));
        }
    }

    private function copyStreamTo(string $targetPath): void
    {
        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            throw new RuntimeException(sprintf('Unable to open target path "%s" for writing', $targetPath));
        }

        $source = $this->getStream();

        if ($source->isSeekable()) {
            $source->rewind();
        }

        $sourceResource = $source->detach();

        if ($sourceResource !== null) {
            $copied = stream_copy_to_stream($sourceResource, $target);
            fclose($target);

            if ($copied === false) {
                throw new RuntimeException('Failed to copy stream to target');
            }

            return;
        }

        // Fallback: read contents manually if detach returned null
        $source = $this->getStream();
        if ($source->isSeekable()) {
            $source->rewind();
        }
        $contents = $source->getContents();
        $written = fwrite($target, $contents);
        fclose($target);

        if ($written === false) {
            throw new RuntimeException('Failed to write stream contents to target');
        }
    }
}
