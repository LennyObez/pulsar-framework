<?php

declare(strict_types=1);

namespace Pulsar\Http;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * HTTP request methods.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods
 */
#[Api(since: '1.0.0')]
enum Method: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case CONNECT = 'CONNECT';
    case OPTIONS = 'OPTIONS';
    case TRACE = 'TRACE';
    case PATCH = 'PATCH';

    /**
     * Check if the method is considered safe (no side effects).
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Check if the method is idempotent.
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Check if the method may have a request body.
     */
    public function mayHaveBody(): bool
    {
        return match ($this) {
            self::POST, self::PUT, self::PATCH => true,
            default => false,
        };
    }

    /**
     * Create from string, case-insensitive.
     */
    #[NoDiscard]
    public static function fromString(string $method): self
    {
        return self::from(strtoupper($method));
    }
}
