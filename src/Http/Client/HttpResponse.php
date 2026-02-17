<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

use function json_decode;

/**
 * Immutable HTTP client response.
 *
 * Wraps the raw response data from an HTTP request and provides
 * convenience accessors for status, headers, and body parsing.
 */
#[Api(since: '1.0.0')]
readonly class HttpResponse
{
    public function __construct(
        private ResponseStatus $statusCode,
        private HeaderBag $headerBag,
        private string $rawBody,
    ) {}

    /**
     * Get the HTTP status code.
     */
    public function status(): int
    {
        return $this->statusCode->value;
    }

    /**
     * Get the response status enum.
     */
    public function statusEnum(): ResponseStatus
    {
        return $this->statusCode;
    }

    /**
     * Get the raw response body.
     */
    public function body(): string
    {
        return $this->rawBody;
    }

    /**
     * Decode the response body as JSON.
     *
     * @return mixed
     *
     * @throws HttpClientException If the body is not valid JSON
     */
    public function json(): mixed
    {
        try {
            return json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HttpClientException::invalidJson($e);
        }
    }

    /**
     * Get the response headers.
     */
    public function headers(): HeaderBag
    {
        return $this->headerBag;
    }

    /**
     * Get a single header value.
     */
    public function header(string $name): ?string
    {
        return $this->headerBag->first($name);
    }

    /**
     * Check if the response indicates success (2xx).
     */
    public function ok(): bool
    {
        return $this->statusCode->isSuccessful();
    }

    /**
     * Check if the response indicates a redirect (3xx).
     */
    public function redirect(): bool
    {
        return $this->statusCode->isRedirection();
    }

    /**
     * Check if the response indicates a client error (4xx).
     */
    public function clientError(): bool
    {
        return $this->statusCode->isClientError();
    }

    /**
     * Check if the response indicates a server error (5xx).
     */
    public function serverError(): bool
    {
        return $this->statusCode->isServerError();
    }

    /**
     * Check if the response indicates failure (4xx or 5xx).
     */
    public function failed(): bool
    {
        return $this->statusCode->isError();
    }

    /**
     * Throw an exception if the response indicates failure.
     *
     * @throws HttpClientException If the response status is 4xx or 5xx
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw HttpClientException::requestFailed(
                $this->statusCode,
                $this->rawBody,
            );
        }

        return $this;
    }

    /**
     * Check if the response has a specific status code.
     */
    public function isStatus(int $code): bool
    {
        return $this->statusCode->value === $code;
    }

    /**
     * Get the Content-Type header value.
     */
    public function contentType(): ?string
    {
        return $this->headerBag->first('Content-Type');
    }

    /**
     * Check if the response body is JSON (based on Content-Type header).
     */
    public function isJson(): bool
    {
        $contentType = $this->contentType();

        if ($contentType === null) {
            return false;
        }

        return str_contains($contentType, 'application/json')
            || str_contains($contentType, '+json');
    }

    /**
     * Construct from raw status code, headers array, and body.
     *
     * @param int $statusCode
     * @param array<string, string|list<string>> $headers
     * @param string $body
     */
    #[NoDiscard]
    public static function fromRaw(int $statusCode, array $headers, string $body): self
    {
        $status = ResponseStatus::tryFrom($statusCode)
            ?? throw new RuntimeException("Unknown HTTP status code: {$statusCode}");

        return new self($status, new HeaderBag($headers), $body);
    }
}
