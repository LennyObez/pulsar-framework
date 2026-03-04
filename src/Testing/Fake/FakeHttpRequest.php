<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Pulsar\Api\Api;

use function array_key_exists;
use function is_array;

/**
 * Represents a recorded HTTP request made through HttpClientFake.
 */
#[Api(since: '1.0.0')]
final readonly class FakeHttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $body = '',
        public array $headers = [],
    ) {}

    /**
     * Check if the request body contains a JSON key-value pair.
     */
    public function hasJsonKey(string $key): bool
    {
        $data = json_decode($this->body, true);

        return is_array($data) && array_key_exists($key, $data);
    }

    /**
     * Get a decoded JSON value from the request body.
     */
    public function jsonValue(string $key): mixed
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? ($data[$key] ?? null) : null;
    }
}
