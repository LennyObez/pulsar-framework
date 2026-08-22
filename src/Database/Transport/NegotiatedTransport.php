<?php

declare(strict_types=1);

namespace Pulsar\Database\Transport;

use Pulsar\Api\Api;

/**
 * What a live session's transport turned out to be, as the server reports it.
 *
 * The absence of this object is a third state and a meaningful one: a caller holding
 * null was told nothing, which is not the same as being told the session is plaintext.
 * See {@see TransportSecurity::negotiated()}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class NegotiatedTransport
{
    /**
     * @param bool $encrypted Whether the session is carried over an encrypted transport.
     */
    public function __construct(
        public bool $encrypted,
        /**
         * What the server actually said, quotable in a report. Stated for both
         * outcomes, so a negative answer names what stood there instead of TLS
         * rather than leaving the reader with a bare false.
         *
         * @var non-empty-string
         */
        public string $detail,
    ) {}
}
