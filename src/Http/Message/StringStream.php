<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use NoDiscard;
use Override;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use RuntimeException;

use function strlen;
use function substr;

/**
 * Lightweight PSR-7 stream backed by an in-memory string.
 *
 * Avoids the overhead of opening a php://temp resource for simple
 * string response bodies. This is the default body representation
 * for factory methods like Response::json(), Response::html(), and
 * Response::text().
 */
#[Api(since: '1.0.0-rc.11')]
final class StringStream implements StreamInterface
{
    private int $position = 0;

    private ?string $content;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    #[Override]
    public function __toString(): string
    {
        if ($this->content === null) {
            return '';
        }

        $this->position = strlen($this->content);

        return $this->content;
    }

    #[Override]
    public function close(): void
    {
        $this->content = null;
        $this->position = 0;
    }

    #[Override]
    public function detach(): null
    {
        $this->content = null;
        $this->position = 0;

        return null;
    }

    #[Override]
    #[NoDiscard]
    public function getSize(): ?int
    {
        return $this->content !== null ? strlen($this->content) : null;
    }

    #[Override]
    #[NoDiscard]
    public function tell(): int
    {
        if ($this->content === null) {
            throw new RuntimeException('Stream is detached');
        }

        return $this->position;
    }

    #[Override]
    #[NoDiscard]
    public function eof(): bool
    {
        return $this->content === null || $this->position >= strlen($this->content);
    }

    #[Override]
    #[NoDiscard]
    public function isSeekable(): bool
    {
        return $this->content !== null;
    }

    #[Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->content === null) {
            throw new RuntimeException('Stream is detached');
        }

        $length = strlen($this->content);

        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $length + $offset,
            default => throw new RuntimeException('Invalid whence value'),
        };

        if ($this->position < 0) {
            $this->position = 0;
        }

        if ($this->position > $length) {
            $this->position = $length;
        }
    }

    #[Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    #[Override]
    #[NoDiscard]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function write(string $string): int
    {
        throw new RuntimeException('StringStream is read-only');
    }

    #[Override]
    #[NoDiscard]
    public function isReadable(): bool
    {
        return $this->content !== null;
    }

    #[Override]
    #[NoDiscard]
    public function read(int $length): string
    {
        if ($this->content === null) {
            throw new RuntimeException('Stream is detached');
        }

        $data = substr($this->content, $this->position, $length);
        $this->position += strlen($data);

        return $data;
    }

    #[Override]
    #[NoDiscard]
    public function getContents(): string
    {
        if ($this->content === null) {
            throw new RuntimeException('Stream is detached');
        }

        $remaining = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $remaining;
    }

    #[Override]
    #[NoDiscard]
    public function getMetadata(?string $key = null): mixed
    {
        if ($key !== null) {
            return match ($key) {
                'timed_out' => false,
                'blocked' => true,
                'eof' => $this->eof(),
                'unread_bytes' => 0,
                'stream_type' => 'MEMORY',
                'wrapper_type' => 'PHP',
                'mode' => 'r',
                'seekable' => $this->isSeekable(),
                'uri' => '',
                default => null,
            };
        }

        return [
            'timed_out' => false,
            'blocked' => true,
            'eof' => $this->eof(),
            'unread_bytes' => 0,
            'stream_type' => 'MEMORY',
            'wrapper_type' => 'PHP',
            'mode' => 'r',
            'seekable' => $this->isSeekable(),
            'uri' => '',
        ];
    }
}
