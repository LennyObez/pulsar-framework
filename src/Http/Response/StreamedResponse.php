<?php

declare(strict_types=1);

namespace Pulsar\Http\Response;

use ArrayIterator;
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
     * Chunks already pulled off {@see $source}, shared by every clone.
     *
     * {@see getBody()} used to consume the single-pass source and keep none of
     * it, so one body-reading middleware anywhere in the stack left the emitter
     * with a response whose `getSource()` THREW on the way out: headers already
     * sent, body empty. {@see StreamedBodyBuffer} explains why the buffer has to
     * be an object for the clones this class hands out.
     */
    private readonly StreamedBodyBuffer $materialized;

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
        $this->materialized = new StreamedBodyBuffer();
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
     *
     * Once {@see getBody()} has materialized the response the original iterator
     * is spent, and replaying it is what throws, so the buffered chunks become
     * the source from then on: a middleware that read the body no longer empties
     * the response for the emitter behind it. Until then the caller gets the
     * iterator it passed in, untouched, and the response streams as before.
     */
    #[NoDiscard]
    public function getSource(): Iterator
    {
        $chunks = $this->materialized->chunks();

        return $chunks === null ? $this->source : new ArrayIterator($chunks);
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

    /**
     * The whole body as a PSR-7 stream.
     *
     * Materializing is not free — it buffers the payload this class exists to
     * avoid buffering — but it is not DESTRUCTIVE either: the source is read
     * once, kept, and answered from the buffer on every later call, including
     * {@see getSource()}'s. Callers that need the response to stay streamed read
     * getSource() and never touch this method.
     */
    #[Override]
    #[NoDiscard]
    public function getBody(): StreamInterface
    {
        return new StringStream(implode('', $this->chunks()));
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

    /**
     * Read the source to exhaustion once and keep what it yielded.
     *
     * Non-string chunks contribute nothing, which is what the concatenating
     * version of this loop already did; empty strings are dropped because the
     * emitter refuses to frame a zero-length chunk anyway — a zero-length chunk
     * is the terminator in HTTP/1.1 chunked encoding.
     *
     * @return list<string>
     */
    private function chunks(): array
    {
        $cached = $this->materialized->chunks();

        if ($cached !== null) {
            return $cached;
        }

        $chunks = [];

        /** @var mixed $chunk */
        foreach ($this->source as $chunk) {
            if (is_string($chunk) && $chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $this->materialized->store($chunks);

        return $chunks;
    }
}
