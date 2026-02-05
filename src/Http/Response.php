<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Pulsar\Api\Api;

/**
 * Immutable HTTP response value object.
 */
#[Api]
readonly class Response
{
    public function __construct(
        public string $body = '',
        public ResponseStatus $status = ResponseStatus::OK,
        public HeaderBag $headers = new HeaderBag(),
        public string $protocolVersion = '1.1',
    ) {}

    /**
     * Return a new response with the given body.
     */
    public function withBody(string $body): self
    {
        return new self(
            body: $body,
            status: $this->status,
            headers: $this->headers,
            protocolVersion: $this->protocolVersion,
        );
    }

    /**
     * Return a new response with the given status.
     */
    public function withStatus(ResponseStatus $status): self
    {
        return new self(
            body: $this->body,
            status: $status,
            headers: $this->headers,
            protocolVersion: $this->protocolVersion,
        );
    }

    /**
     * Return a new response with the given header.
     *
     * @param string|list<string> $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        return new self(
            body: $this->body,
            status: $this->status,
            headers: $this->headers->with($name, $value),
            protocolVersion: $this->protocolVersion,
        );
    }

    /**
     * Return a new response with an added header value.
     */
    public function withAddedHeader(string $name, string $value): self
    {
        return new self(
            body: $this->body,
            status: $this->status,
            headers: $this->headers->withAdded($name, $value),
            protocolVersion: $this->protocolVersion,
        );
    }

    /**
     * Return a new response without the given header.
     */
    public function withoutHeader(string $name): self
    {
        return new self(
            body: $this->body,
            status: $this->status,
            headers: $this->headers->without($name),
            protocolVersion: $this->protocolVersion,
        );
    }

    /**
     * Return a new response with the given protocol version.
     */
    public function withProtocolVersion(string $version): self
    {
        return new self(
            body: $this->body,
            status: $this->status,
            headers: $this->headers,
            protocolVersion: $version,
        );
    }

    /**
     * Check if the response body is empty.
     */
    public function isEmpty(): bool
    {
        return $this->body === '';
    }

    /**
     * Get the Content-Length, if set.
     */
    public function contentLength(): ?int
    {
        $value = $this->headers->first('Content-Length');
        return $value !== null ? (int) $value : null;
    }

    /**
     * Get the Content-Type, if set.
     */
    public function contentType(): ?string
    {
        return $this->headers->first('Content-Type');
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data Data to encode as JSON
     * @param ResponseStatus $status HTTP response status
     * @param int $options JSON encoding options
     *
     * @return self
     */
    public static function json(
        mixed $data,
        ResponseStatus $status = ResponseStatus::OK,
        int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): self {
        // JSON_THROW_ON_ERROR ensures this returns string or throws
        $encoded = json_encode($data, $options);
        $body = $encoded === false ? '' : $encoded;

        return new self(
            body: $body,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'application/json; charset=utf-8',
            ]),
        );
    }

    /**
     * Create an HTML response.
     */
    public static function html(
        string $html,
        ResponseStatus $status = ResponseStatus::OK,
    ): self {
        return new self(
            body: $html,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        );
    }

    /**
     * Create a plain text response.
     */
    public static function text(
        string $text,
        ResponseStatus $status = ResponseStatus::OK,
    ): self {
        return new self(
            body: $text,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'text/plain; charset=utf-8',
            ]),
        );
    }

    /**
     * Create a redirect response.
     */
    public static function redirect(
        string $url,
        ResponseStatus $status = ResponseStatus::Found,
    ): self {
        return new self(
            body: '',
            status: $status,
            headers: new HeaderBag([
                'Location' => $url,
            ]),
        );
    }

    /**
     * Create an empty response (204 No Content).
     */
    public static function noContent(): self
    {
        return new self(
            body: '',
            status: ResponseStatus::NoContent,
        );
    }

    /**
     * Create a 422 JSON response for validation errors.
     *
     * @param list<array{field: string, message: string, rule: string}> $violations
     */
    public static function validationError(array $violations): self
    {
        return self::json(
            data: [
                'error' => 'Validation Failed',
                'status' => 422,
                'violations' => $violations,
            ],
            status: ResponseStatus::UnprocessableEntity,
        );
    }
}
