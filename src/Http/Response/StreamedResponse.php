<?php

declare(strict_types=1);

namespace Pulsar\Http\Response;

use Generator;
use Iterator;
use NoDiscard;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\StringStream;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

use function implode;
use function is_array;
use function is_string;
use function strtolower;

/**
 * HTTP response for streaming large payloads via Transfer-Encoding: chunked.
 *
 * Streams data from a generator or iterator without buffering the full
 * response body in memory. Suitable for large dataset exports, file
 * downloads, and server-sent events.
 * @api
 */
#[Api(since: '1.0.0')]
final class StreamedResponse implements ResponseInterface
{
    private string $protocolVersion = '1.1';

    /** @var array<string, array<string>> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $headerNames = [];

    private int $statusCode;

    private string $reasonPhrase;

    private readonly Iterator $source;

    /**
     * @param Iterator|Generator $source Data source to stream
     * @param int $statusCode HTTP status code
     * @param array<string, string|list<string>> $headers Response headers
     */
    public function __construct(
        Iterator|Generator $source,
        int $statusCode = 200,
        array $headers = [],
    ) {
        $this->source = $source;
        $this->statusCode = $statusCode;

        $status = ResponseStatus::tryFrom($statusCode);
        $this->reasonPhrase = $status?->reasonPhrase() ?? '';

        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);
            $this->headerNames[$normalized] = $name;
            $this->headers[$normalized] = is_array($value) ? $value : [$value];
        }

        // Set Transfer-Encoding: chunked if not explicitly overridden
        if (!isset($this->headerNames['transfer-encoding'])) {
            $this->headerNames['transfer-encoding'] = 'Transfer-Encoding';
            $this->headers['transfer-encoding'] = ['chunked'];
        }
    }

    /**
     * Get the data source iterator.
     *
     * Each yielded value is sent as a chunk. Values must be string-castable.
     */
    #[NoDiscard]
    public function getSource(): Iterator
    {
        return $this->source;
    }

    /**
     * Create a StreamedResponse from a generator function.
     *
     * @param callable(): Generator<int, string, mixed, void> $generatorFactory
     * @param int $statusCode HTTP status code
     * @param array<string, string|list<string>> $headers
     */
    #[NoDiscard]
    public static function fromGenerator(
        callable $generatorFactory,
        int $statusCode = 200,
        array $headers = [],
    ): self {
        return new self($generatorFactory(), $statusCode, $headers);
    }

    #[Override]
    #[NoDiscard]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[Override]
    #[NoDiscard]
    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    #[Override]
    #[NoDiscard]
    public function getHeaders(): array
    {
        $result = [];

        foreach ($this->headers as $normalized => $values) {
            $name = $this->headerNames[$normalized];
            $result[$name] = $values;
        }

        return $result;
    }

    #[Override]
    #[NoDiscard]
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    #[Override]
    #[NoDiscard]
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    #[Override]
    #[NoDiscard]
    public function getHeaderLine(string $name): string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return implode(', ', $values);
    }

    #[Override]
    #[NoDiscard]
    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headerNames[$normalized] = $name;
        $clone->headers[$normalized] = is_array($value) ? $value : [$value];

        return $clone;
    }

    #[Override]
    #[NoDiscard]
    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $normalized = strtolower($name);

        if (!isset($clone->headerNames[$normalized])) {
            $clone->headerNames[$normalized] = $name;
            $clone->headers[$normalized] = [];
        }

        $newValues = is_array($value) ? $value : [$value];
        $clone->headers[$normalized] = [...$clone->headers[$normalized], ...$newValues];

        return $clone;
    }

    #[Override]
    #[NoDiscard]
    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        unset($clone->headers[$normalized], $clone->headerNames[$normalized]);

        return $clone;
    }

    #[Override]
    #[NoDiscard]
    public function getBody(): StreamInterface
    {
        // For PSR-7 compatibility, materialize the stream content.
        // Callers that need streaming should use getSource() instead.
        $content = '';

        foreach ($this->source as $chunk) {
            $content .= (is_string($chunk) ? $chunk : '');
        }

        return new StringStream($content);
    }

    #[Override]
    #[NoDiscard]
    public function withBody(StreamInterface $body): static
    {
        throw new RuntimeException(
            'StreamedResponse does not support withBody(). Use a regular Response for non-streamed bodies.',
        );
    }

    #[Override]
    #[NoDiscard]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[Override]
    #[NoDiscard]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (ResponseStatus::tryFrom($code)?->reasonPhrase() ?? '');

        return $clone;
    }

    #[Override]
    #[NoDiscard]
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
