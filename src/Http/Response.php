<?php

declare(strict_types=1);

namespace Pulsar\Http;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable HTTP response value object.
 */
#[Api(since: '1.0.0')]
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
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
     */
    #[NoDiscard]
    public function withBody(string $body): self
    {
        return clone($this, ['body' => $body]);
    }

    /**
     * Return a new response with the given status.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withStatus(ResponseStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * Return a new response with the given header.
     *
     * @param string|list<string> $value
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withHeader(string $name, string|array $value): self
    {
        return clone($this, ['headers' => $this->headers->with($name, $value)]);
    }

    /**
     * Return a new response with an added header value.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withAddedHeader(string $name, string $value): self
    {
        return clone($this, ['headers' => $this->headers->withAdded($name, $value)]);
    }

    /**
     * Return a new response without the given header.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withoutHeader(string $name): self
    {
        return clone($this, ['headers' => $this->headers->without($name)]);
    }

    /**
     * Return a new response with the given protocol version.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withProtocolVersion(string $version): self
    {
        return clone($this, ['protocolVersion' => $version]);
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
    #[NoDiscard]
    public static function json(
        mixed $data,
        ResponseStatus $status = ResponseStatus::OK,
        int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): self {
        /** @var non-empty-string $body JSON_THROW_ON_ERROR guarantees string return */
        $body = json_encode($data, $options);

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
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
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
    #[NoDiscard]
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
