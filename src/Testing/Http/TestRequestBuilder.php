<?php

declare(strict_types=1);

namespace Pulsar\Testing\Http;

use Pulsar\Api\Api;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\Uri;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Fluent builder for constructing PSR-7 server requests in tests.
 *
 * Usage:
 *   $request = TestRequestBuilder::get('/api/users')
 *       ->withHeader('Accept', 'application/json')
 *       ->withToken('test-bearer-token')
 *       ->build();
 */
#[Api(since: '1.0.0')]
final class TestRequestBuilder
{
    private string $method;

    private string $uri;

    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    /** @var array<string, mixed> */
    private array $serverParams = [];

    /** @var array<string, string> */
    private array $cookieParams = [];

    /** @var array<string, mixed> */
    private array $queryParams = [];

    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    private function __construct(string $method, string $uri)
    {
        $this->method = $method;
        $this->uri = $uri;
    }

    /**
     * Create a GET request builder.
     */
    public static function get(string $uri): self
    {
        return new self('GET', $uri);
    }

    /**
     * Create a POST request builder.
     */
    public static function post(string $uri): self
    {
        return new self('POST', $uri);
    }

    /**
     * Create a PUT request builder.
     */
    public static function put(string $uri): self
    {
        return new self('PUT', $uri);
    }

    /**
     * Create a PATCH request builder.
     */
    public static function patch(string $uri): self
    {
        return new self('PATCH', $uri);
    }

    /**
     * Create a DELETE request builder.
     */
    public static function delete(string $uri): self
    {
        return new self('DELETE', $uri);
    }

    /**
     * Add a header.
     *
     * @return $this
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Add multiple headers.
     *
     * @param array<string, string> $headers
     *
     * @return $this
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * Add a Bearer token authorization header.
     *
     * @return $this
     */
    public function withToken(string $token): self
    {
        $this->headers['Authorization'] = 'Bearer ' . $token;

        return $this;
    }

    /**
     * Set a JSON request body.
     *
     * @param array<string, mixed> $data
     *
     * @return $this
     */
    public function withJson(array $data): self
    {
        $this->body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->headers['Content-Type'] = 'application/json';
        $this->parsedBody = $data;

        return $this;
    }

    /**
     * Set the raw request body.
     *
     * @return $this
     */
    public function withBody(string $body, string $contentType = 'text/plain'): self
    {
        $this->body = $body;
        $this->headers['Content-Type'] = $contentType;

        return $this;
    }

    /**
     * Add query parameters.
     *
     * @param array<string, mixed> $params
     *
     * @return $this
     */
    public function withQuery(array $params): self
    {
        $this->queryParams = $params;

        return $this;
    }

    /**
     * Add cookies.
     *
     * @param array<string, string> $cookies
     *
     * @return $this
     */
    public function withCookies(array $cookies): self
    {
        $this->cookieParams = $cookies;

        return $this;
    }

    /**
     * Set server parameters.
     *
     * @param array<string, mixed> $params
     *
     * @return $this
     */
    public function withServerParams(array $params): self
    {
        $this->serverParams = $params;

        return $this;
    }

    /**
     * Build the PSR-7 server request.
     */
    public function build(): ServerRequest
    {
        /** @var array<string, list<string>> $headerArrays */
        $headerArrays = [];

        foreach ($this->headers as $name => $value) {
            $headerArrays[$name] = [$value];
        }

        $request = new ServerRequest(
            method: $this->method,
            uri: Uri::fromString($this->uri),
            headers: $headerArrays,
            body: Stream::create($this->body),
            serverParams: $this->serverParams,
        );

        if ($this->queryParams !== []) {
            $request = $request->withQueryParams($this->queryParams);
        }

        if ($this->cookieParams !== []) {
            $request = $request->withCookieParams($this->cookieParams);
        }

        if ($this->parsedBody !== null) {
            $request = $request->withParsedBody($this->parsedBody);
        }

        return $request;
    }
}
