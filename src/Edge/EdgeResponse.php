<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Response from an edge function.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
 */
#[Api(since: '1.0.0')]
final readonly class EdgeResponse
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function __construct(
        public int $statusCode,
        public string $body,
        public array $headers,
        public array $cookies,
    ) {}

    /**
     * Create a redirect response.
     */
    #[NoDiscard]
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self(
            statusCode: $statusCode,
            body: '',
            headers: ['Location' => $url],
            cookies: [],
        );
    }

    /**
     * Create an HTML response.
     */
    #[NoDiscard]
    public static function html(string $html, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            body: $html,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            cookies: [],
        );
    }

    /**
     * Create a JSON response.
     */
    #[NoDiscard]
    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            headers: ['Content-Type' => 'application/json'],
            cookies: [],
        );
    }

    /**
     * Create a block/deny response (403 Forbidden).
     */
    #[NoDiscard]
    public static function deny(string $reason = 'Access denied'): self
    {
        return new self(
            statusCode: 403,
            body: $reason,
            headers: ['Content-Type' => 'text/plain'],
            cookies: [],
        );
    }

    /**
     * Add a header to the response.
     */
    #[NoDiscard]
    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return clone($this, ['headers' => $headers]);
    }

    /**
     * Add a cookie to the response.
     */
    #[NoDiscard]
    public function withCookie(string $name, string $value): self
    {
        $cookies = $this->cookies;
        $cookies[$name] = $value;

        return clone($this, ['cookies' => $cookies]);
    }
}
