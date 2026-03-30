<?php

declare(strict_types=1);

namespace Pulsar\Http\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when an incoming HTTP request body exceeds the configured
 * `maxBodyBytes` ceiling.
 *
 * Without an upper bound, an attacker can post an arbitrarily large body
 * and exhaust worker memory before the application even begins to parse
 * the request — a cheap denial-of-service vector that the framework must
 * fail closed against. Catching this exception in the kernel lets the
 * application reply with `413 Payload Too Large` instead of crashing.
 */
#[Api(since: '1.0.0')]
final class BodyTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $maxBodyBytes,
        public readonly int $declaredContentLength = 0,
    ) {
        parent::__construct(sprintf(
            'Request body exceeds the configured maximum of %d bytes (declared Content-Length: %d).',
            $maxBodyBytes,
            $declaredContentLength,
        ));
    }
}
