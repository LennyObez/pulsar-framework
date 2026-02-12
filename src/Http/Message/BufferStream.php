<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function fopen;

/**
 * In-memory rewindable stream for buffered body content.
 *
 * Uses a php://temp resource that is always seekable and readable.
 * Used by ServerRequest::bufferBody() to provide a rewindable body stream.
 */
#[Api(since: '1.0.0-rc.11')]
final class BufferStream extends Stream
{
    public function __construct(string $content = '')
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw new RuntimeException('Unable to create php://temp stream for buffer');
        }

        parent::__construct($resource);

        if ($content !== '') {
            $this->write($content);
            $this->rewind();
        }
    }

    /**
     * Create a buffer stream from an existing stream's contents.
     */
    #[NoDiscard]
    public static function fromStream(Stream $source): self
    {
        if ($source->isSeekable()) {
            $source->rewind();
        }

        return new self($source->getContents());
    }
}
