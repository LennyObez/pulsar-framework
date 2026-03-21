<?php

declare(strict_types=1);

namespace Pulsar\Http\Turbo;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Builds an HTTP response containing one or more Turbo Stream elements.
 *
 * The response uses the text/vnd.turbo-stream.html content type so
 * the client runtime processes each <pulsar-stream> element as a
 * DOM mutation instruction.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TurboStreamResponse
{
    /** @var list<TurboStream> */
    private array $streams;

    /**
     * @param list<TurboStream> $streams
     */
    private function __construct(array $streams)
    {
        $this->streams = $streams;
    }

    /**
     * Create a new empty stream response builder.
     */
    #[NoDiscard]
    public static function create(): self
    {
        return new self([]);
    }

    /**
     * Add a stream to this response.
     */
    #[NoDiscard]
    public function withStream(TurboStream $stream): self
    {
        return clone($this, ['streams' => [...$this->streams, $stream]]);
    }

    /**
     * Convenience: append HTML to a target.
     */
    #[NoDiscard]
    public function append(string $target, string $html): self
    {
        return $this->withStream(TurboStream::append($target, $html));
    }

    /**
     * Convenience: prepend HTML to a target.
     */
    #[NoDiscard]
    public function prepend(string $target, string $html): self
    {
        return $this->withStream(TurboStream::prepend($target, $html));
    }

    /**
     * Convenience: replace a target element.
     */
    #[NoDiscard]
    public function replace(string $target, string $html): self
    {
        return $this->withStream(TurboStream::replace($target, $html));
    }

    /**
     * Convenience: update inner HTML of a target.
     */
    #[NoDiscard]
    public function update(string $target, string $html): self
    {
        return $this->withStream(TurboStream::update($target, $html));
    }

    /**
     * Convenience: remove a target element.
     */
    #[NoDiscard]
    public function remove(string $target): self
    {
        return $this->withStream(TurboStream::remove($target));
    }

    /**
     * Build the HTTP response with all accumulated streams.
     */
    #[NoDiscard]
    public function toResponse(ResponseStatus $status = ResponseStatus::OK): Response
    {
        $body = '';
        foreach ($this->streams as $stream) {
            $body .= $stream->toHtml() . "\n";
        }

        return new Response(
            body: $body,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8',
            ]),
        );
    }

    /**
     * Get all streams in this response.
     *
     * @return list<TurboStream>
     */
    public function streams(): array
    {
        return $this->streams;
    }
}
