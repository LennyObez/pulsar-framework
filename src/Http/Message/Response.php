<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use NoDiscard;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;

use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * PSR-7 response — the canonical HTTP response object for Pulsar.
 *
 * Implements ResponseInterface with Pulsar-specific convenience factories.
 * Headers are stored lowercase internally with deterministic iteration order.
 */
#[Api(since: '1.0.0-rc.11')]
class Response implements ResponseInterface
{
    private string $protocolVersion;

    /**
     * @var array<string, list<string>>
     */
    private array $headers;

    /**
     * @var array<string, string>
     */
    private array $headerNames;

    private StreamInterface $body;

    private int $statusCode;

    private string $reasonPhrase;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        int $statusCode = 200,
        string $reasonPhrase = '',
        array $headers = [],
        StreamInterface|string $body = '',
        string $protocolVersion = '1.1',
    ) {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : self::defaultReasonPhrase($statusCode);
        $this->body = is_string($body) ? Stream::create($body) : $body;
        $this->protocolVersion = $protocolVersion;

        $this->headers = [];
        $this->headerNames = [];

        foreach ($headers as $name => $value) {
            $lowered = strtolower($name);
            $this->headerNames[$lowered] = $name;
            $this->headers[$lowered] = is_array($value) ? $value : [$value];
        }

        ksort($this->headers);
    }

    // ── Pulsar Convenience Factories ────────────────────────────────────

    /**
     * Create a JSON response.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function json(
        array $data,
        int $status = 200,
        int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): self {
        /** @var non-empty-string $body */
        $body = json_encode($data, $options);

        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
            body: $body,
        );
    }

    /**
     * Create an HTML response.
     */
    #[NoDiscard]
    public static function html(string $html, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $html,
        );
    }

    /**
     * Create a plain text response.
     */
    #[NoDiscard]
    public static function text(string $text, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            body: $text,
        );
    }

    /**
     * Create a redirect response.
     */
    #[NoDiscard]
    public static function redirect(string $url, int $status = 302): self
    {
        return new self(
            statusCode: $status,
            headers: ['Location' => $url],
        );
    }

    /**
     * Create an empty 204 No Content response.
     */
    #[NoDiscard]
    public static function noContent(): self
    {
        return new self(statusCode: 204);
    }

    /**
     * Create a 422 JSON response for validation errors.
     *
     * @param list<array{field: string, message: string, rule: string, code?: string}> $violations
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
            status: 422,
        );
    }

    // ── PSR-7 MessageInterface ──────────────────────────────────────────

    #[Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[NoDiscard]
    #[Override]
    public function withProtocolVersion(string $version): static
    {
        if ($version === $this->protocolVersion) {
            return $this;
        }

        return clone($this, ['protocolVersion' => $version]);
    }

    /**
     * @return array<string, list<string>>
     */
    #[Override]
    public function getHeaders(): array
    {
        $result = [];

        foreach ($this->headers as $lowered => $values) {
            $result[$this->headerNames[$lowered]] = $values;
        }

        return $result;
    }

    #[Override]
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    #[Override]
    public function getHeaderLine(string $name): string
    {
        $values = $this->getHeader($name);

        return $values === [] ? '' : implode(', ', $values);
    }

    #[NoDiscard]
    #[Override]
    public function withHeader(string $name, $value): static
    {
        /** @var list<string> $values */
        $values = is_array($value) ? $value : [$value];
        $lowered = strtolower($name);

        $new = clone $this;
        $new->headerNames[$lowered] = $name;
        $new->headers[$lowered] = $values;
        ksort($new->headers);

        return $new;
    }

    #[NoDiscard]
    #[Override]
    public function withAddedHeader(string $name, $value): static
    {
        /** @var list<string> $values */
        $values = is_array($value) ? $value : [$value];
        $lowered = strtolower($name);

        $new = clone $this;

        if (isset($new->headers[$lowered])) {
            /** @var list<string> $merged */
            $merged = [...$new->headers[$lowered], ...$values];
            $new->headers[$lowered] = $merged;
        } else {
            $new->headerNames[$lowered] = $name;
            $new->headers[$lowered] = $values;
            ksort($new->headers);
        }

        return $new;
    }

    #[NoDiscard]
    #[Override]
    public function withoutHeader(string $name): static
    {
        $lowered = strtolower($name);

        if (!isset($this->headers[$lowered])) {
            return $this;
        }

        $new = clone $this;
        unset($new->headers[$lowered], $new->headerNames[$lowered]);

        return $new;
    }

    #[Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[NoDiscard]
    #[Override]
    public function withBody(StreamInterface $body): static
    {
        return clone($this, ['body' => $body]);
    }

    // ── PSR-7 ResponseInterface ─────────────────────────────────────────

    #[Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[NoDiscard]
    #[Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return clone($this, [
            'statusCode' => $code,
            'reasonPhrase' => $reasonPhrase !== '' ? $reasonPhrase : self::defaultReasonPhrase($code),
        ]);
    }

    #[Override]
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // ── Pulsar Convenience Inspectors ───────────────────────────────────

    /**
     * Check if the response body is empty.
     */
    public function isEmpty(): bool
    {
        return (string) $this->body === '';
    }

    /**
     * Get the Content-Length, if set.
     */
    public function contentLength(): ?int
    {
        $values = $this->getHeader('Content-Length');

        return $values !== [] ? (int) $values[0] : null;
    }

    /**
     * Get the Content-Type, if set.
     */
    public function contentType(): ?string
    {
        $values = $this->getHeader('Content-Type');

        return $values !== [] ? $values[0] : null;
    }

    /**
     * Default reason phrases per RFC 7231 / IANA registry.
     */
    private static function defaultReasonPhrase(int $code): string
    {
        $status = ResponseStatus::tryFrom($code);

        return $status?->reasonPhrase() ?? '';
    }
}
