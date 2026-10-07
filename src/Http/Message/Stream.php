<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use NoDiscard;
use Override;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use RuntimeException;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function is_resource;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function stream_get_meta_data;

use const SEEK_SET;

/**
 * PSR-7 stream wrapper around a PHP resource.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private bool $readable;

    private bool $writable;

    private bool $seekable;

    public function __construct(mixed $resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Stream requires a valid PHP resource');
        }

        $this->resource = $resource;

        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'];

        $this->readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $this->writable = str_contains($mode, 'w')
            || str_contains($mode, 'a')
            || str_contains($mode, 'x')
            || str_contains($mode, 'c')
            || str_contains($mode, '+');
        $this->seekable = $meta['seekable'];
    }

    /**
     * Create a stream from a string content using a php://temp resource.
     */
    #[NoDiscard]
    public static function create(string $content = ''): self
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw new RuntimeException('Unable to create php://temp stream');
        }

        $stream = new self($resource);

        if ($content !== '') {
            $stream->write($content);
            $stream->rewind();
        }

        return $stream;
    }

    /**
     * Create a stream from a file path.
     */
    #[NoDiscard]
    public static function fromFile(string $filename, string $mode = 'r'): self
    {
        $resource = @fopen($filename, $mode . 'b');

        if ($resource === false) {
            throw new RuntimeException(sprintf('Unable to open file "%s" with mode "%s"', $filename, $mode));
        }

        return new self($resource);
    }

    #[Override]
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    #[Override]
    public function close(): void
    {
        if ($this->resource !== null) {
            $resource = $this->detach();

            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * @return resource|null
     */
    #[Override]
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $resource;
    }

    #[Override]
    #[NoDiscard]
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        if ($stats === false) {
            return null;
        }

        return $stats['size'];
    }

    #[Override]
    #[NoDiscard]
    public function tell(): int
    {
        $this->assertAttached();

        $position = ftell($this->resource);

        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    #[Override]
    #[NoDiscard]
    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    #[Override]
    #[NoDiscard]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    #[Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        if (!$this->seekable) {
            throw new RuntimeException('Stream is not seekable');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException(sprintf('Unable to seek to position %d', $offset));
        }
    }

    #[Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[Override]
    #[NoDiscard]
    public function isWritable(): bool
    {
        return $this->writable;
    }

    #[Override]
    public function write(string $string): int
    {
        $this->assertAttached();

        if (!$this->writable) {
            throw new RuntimeException('Stream is not writable');
        }

        $bytes = fwrite($this->resource, $string);

        if ($bytes === false) {
            throw new RuntimeException('Unable to write to stream');
        }

        return $bytes;
    }

    #[Override]
    #[NoDiscard]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    #[Override]
    #[NoDiscard]
    public function read(int $length): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable');
        }

        /** @var int<1, max> $length */
        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new RuntimeException('Unable to read from stream');
        }

        return $data;
    }

    #[Override]
    #[NoDiscard]
    public function getContents(): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->resource);

        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    #[Override]
    #[NoDiscard]
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    /**
     * @phpstan-assert !null $this->resource
     */
    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new RuntimeException('Stream is detached');
        }
    }
}
