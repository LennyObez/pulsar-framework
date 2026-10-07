<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Pulsar\Api\Api;

/**
 * A fake HTTP response returned by HttpClientFake stubs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FakeHttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * Create a 200 OK JSON response.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            status: $status,
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Create a failure response.
     */
    public static function failed(int $status = 500, string $body = ''): self
    {
        return new self($status, $body);
    }

    /**
     * Decode the response body as JSON.
     *
     * @return array<string, mixed>
     */
    public function decodeJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Check if the response status indicates success (2xx).
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
