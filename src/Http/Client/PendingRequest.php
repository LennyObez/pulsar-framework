<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use NoDiscard;
use Pulsar\Api\Api;

use function assert;
use function base64_encode;
use function http_build_query;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Fluent request builder for constructing HTTP requests.
 *
 * All builder methods return a new instance (immutable builder pattern)
 * to avoid state leaking between requests.
 */
#[Api(since: '1.0.0')]
final class PendingRequest
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?string $body = null;

    private ?string $contentType = null;

    private ?float $timeout = null;

    private ?int $retries = null;

    private ?float $retryDelay = null;

    /** @var array<string, string> */
    private array $query = [];

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {}

    /**
     * Add headers to the request.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    /**
     * Add a single header.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * Add a Bearer token Authorization header.
     */
    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', sprintf('Bearer %s', $token));
    }

    /**
     * Add a Basic Authentication header.
     */
    public function withBasicAuth(string $username, string $password): self
    {
        $encoded = base64_encode(sprintf('%s:%s', $username, $password));

        return $this->withHeader('Authorization', sprintf('Basic %s', $encoded));
    }

    /**
     * Set a raw string body.
     */
    public function withBody(string $body, string $contentType = 'application/octet-stream'): self
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->contentType = $contentType;

        return $clone;
    }

    /**
     * Set the body as JSON-encoded data.
     *
     * @param mixed $data
     */
    public function asJson(mixed $data): self
    {
        $clone = clone $this;
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        assert(is_string($encoded));
        $clone->body = $encoded;
        $clone->contentType = 'application/json';

        return $clone;
    }

    /**
     * Set the body as form-encoded data.
     *
     * @param array<string, string> $data
     */
    public function asForm(array $data): self
    {
        $clone = clone $this;
        $clone->body = http_build_query($data);
        $clone->contentType = 'application/x-www-form-urlencoded';

        return $clone;
    }

    /**
     * Override the request timeout.
     */
    public function timeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    /**
     * Configure retry behavior.
     */
    public function retry(int $times, float $delay = 1.0): self
    {
        $clone = clone $this;
        $clone->retries = $times;
        $clone->retryDelay = $delay;

        return $clone;
    }

    /**
     * Add query string parameters.
     *
     * @param array<string, string> $params
     */
    public function withQuery(array $params): self
    {
        $clone = clone $this;

        foreach ($params as $key => $value) {
            $clone->query[$key] = $value;
        }

        return $clone;
    }

    /**
     * Accept JSON responses.
     */
    public function acceptJson(): self
    {
        return $this->withHeader('Accept', 'application/json');
    }

    /**
     * Set a User-Agent header.
     */
    public function withUserAgent(string $agent): self
    {
        return $this->withHeader('User-Agent', $agent);
    }

    /**
     * Send a GET request.
     *
     * @throws HttpClientException
     */
    public function get(string $url): HttpResponse
    {
        return $this->client->get($url, $this->buildOptions());
    }

    /**
     * Send a POST request.
     *
     * @throws HttpClientException
     */
    public function post(string $url): HttpResponse
    {
        return $this->client->post($url, $this->buildOptions());
    }

    /**
     * Send a PUT request.
     *
     * @throws HttpClientException
     */
    public function put(string $url): HttpResponse
    {
        return $this->client->put($url, $this->buildOptions());
    }

    /**
     * Send a PATCH request.
     *
     * @throws HttpClientException
     */
    public function patch(string $url): HttpResponse
    {
        return $this->client->patch($url, $this->buildOptions());
    }

    /**
     * Send a DELETE request.
     *
     * @throws HttpClientException
     */
    public function delete(string $url): HttpResponse
    {
        return $this->client->delete($url, $this->buildOptions());
    }

    /**
     * Send a HEAD request.
     *
     * @throws HttpClientException
     */
    public function head(string $url): HttpResponse
    {
        return $this->client->head($url, $this->buildOptions());
    }

    /**
     * Send an OPTIONS request.
     *
     * @throws HttpClientException
     */
    public function options(string $url): HttpResponse
    {
        return $this->client->options($url, $this->buildOptions());
    }

    /**
     * Build the options array from the builder state.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    private function buildOptions(): array
    {
        $options = [];

        if ($this->headers !== []) {
            $options['headers'] = $this->headers;
        }

        if ($this->body !== null) {
            $options['body'] = $this->body;
        }

        if ($this->contentType !== null) {
            $options['headers'] ??= [];
            $options['headers']['Content-Type'] = $this->contentType;
        }

        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
        }

        if ($this->retries !== null) {
            $options['retries'] = $this->retries;
        }

        if ($this->retryDelay !== null) {
            $options['retry_delay'] = $this->retryDelay;
        }

        if ($this->query !== []) {
            $options['query'] = $this->query;
        }

        return $options;
    }
}
