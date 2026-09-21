<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\Api\Internal;

/**
 * A connection that has been opened and whose response headers have arrived,
 * but whose body has not been read.
 *
 * The two halves travel together because PHP delivers them together: the http
 * stream wrapper fills `$http_response_header` in the scope that called
 * `fopen()`, and nowhere else.
 */
#[Internal(reason: 'Transport detail of the AI streaming stack')]
final readonly class OpenedHttpStream
{
    /**
     * @param resource $handle Readable stream positioned at the start of the body
     * @param list<string> $headerLines Raw response header lines, status line first
     */
    public function __construct(
        public mixed $handle,
        public array $headerLines,
    ) {}
}
