<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Exception;
use Generator;
use IteratorAggregate;
use NoDiscard;
use Override;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Api;

/**
 * A streamed AI response: the deltas as they arrive, and the complete
 * {@see AiResponse} once they have all arrived.
 *
 * Iterating yields {@see AiStreamDelta} objects in provider order. When the
 * stream reaches the provider's terminal event, {@see response()} returns the
 * accumulated response — the same object the unstreamed call would have returned
 * for the same bytes, token counts included.
 *
 * Every other outcome is an exception. A stream whose connection died, or whose
 * provider sent an error event, has no complete response and {@see response()}
 * says so rather than inventing one. That is the whole safety property: there is
 * no way to hold a truncated answer in an {@see AiResponse}-shaped variable.
 *
 * This class holds no state of its own — deliberately. Whether a stream has
 * finished, and what it finished with, are facts the underlying generator
 * already carries, and a second copy kept here could only ever disagree with it.
 * {@see response()} therefore drains and asks, rather than remembering.
 *
 * A stream is single-pass: iterating one that has already been read to its end
 * is refused by the engine, and a partially read one resumes where it stopped
 * rather than replaying. Calling {@see response()} after stopping early is
 * allowed and finishes the job — the deltas are gone, but the answer is real.
 *
 * Wrapping one — an audit decorator, a budget decorator — means iterating the
 * inner stream and re-yielding, then taking its `response()` as the wrapper's own
 * generator return value:
 *
 * ```php
 * return new AiStream((function () use ($inner) {
 *     foreach ($inner as $delta) {
 *         yield $delta;
 *     }
 *
 *     return $inner->response();
 * })(), $inner->providerName);
 * ```
 *
 * @implements IteratorAggregate<int, AiStreamDelta>
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiStream implements IteratorAggregate
{
    /**
     * @param Generator<int, AiStreamDelta, mixed, AiResponse> $deltas
     *        Yields the deltas and returns the accumulated response; throws
     *        {@see AiStreamException} instead of returning when the stream fails
     * @param string $providerName Provider that produced the stream, for diagnostics
     */
    public function __construct(
        private Generator $deltas,
        public string $providerName = '',
    ) {}

    /**
     * The deltas, in provider order.
     *
     * @return Generator<int, AiStreamDelta, mixed, void>
     *
     * @throws AiStreamException When the stream fails before its terminal event
     */
    #[Override]
    public function getIterator(): Generator
    {
        // Explicit iteration rather than `yield from`, so that a caller who
        // breaks out leaves the underlying generator suspended and resumable —
        // which is what lets response() still finish the job afterwards.
        foreach ($this->deltas as $delta) {
            yield $delta;
        }
    }

    /**
     * The complete response.
     *
     * Reads the stream to its terminal event when the caller has not already
     * done so, which makes this both the whole-answer call for code that wants
     * streaming transport without streaming consumption, and the way to finish a
     * stream whose deltas the caller stopped reading.
     *
     * @throws AiStreamException When the stream failed before its terminal event
     */
    #[NoDiscard]
    public function response(): AiResponse
    {
        // valid() starts a generator that has not run and reports false for one
        // that has finished, so this drains from wherever the stream is without
        // rewinding it.
        while ($this->deltas->valid()) {
            $this->deltas->next();
        }

        try {
            return $this->deltas->getReturn();
        } catch (Exception $neverReturned) {
            // The generator ended by throwing rather than returning, and the
            // caller has already swallowed that exception. There is still no
            // complete response, and saying so is the only honest answer.
            throw AiStreamException::didNotComplete($this->providerName, $neverReturned);
        }
    }
}
