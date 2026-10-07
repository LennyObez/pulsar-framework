<?php

declare(strict_types=1);

namespace Pulsar\AI\Exception;

use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;
use function strlen;
use function substr;

/**
 * A streamed AI response failed before it was complete.
 *
 * This is a distinct type from {@see AiException} on purpose. A stream that
 * stops early has already handed the caller real text, and the one mistake that
 * must be impossible is treating that text as a finished answer. Every failure
 * path in the streaming stack throws this and nothing returns a partial
 * {@see \Pulsar\AI\AiResponse}, so a caller either iterates to a terminal event
 * or sees an exception — never a truncated answer wearing a complete one's type.
 *
 * {@see $partialContent} carries whatever text did arrive, for callers that want
 * to show it. It is deliberately a plain string: it cannot be mistaken for a
 * response, it carries no token counts, and nothing downstream will bill it.
 * @api
 */
#[Api(since: '1.0.0')]
final class AiStreamException extends RuntimeException
{
    /**
     * @param string $partialContent Text received before the failure; empty when none arrived
     */
    private function __construct(
        string $message,
        public readonly string $partialContent = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The body ended without the provider's terminal event.
     *
     * Every supported provider closes a successful stream with an explicit
     * end-of-message event. Its absence is the signal that the connection died
     * after the headers, which is exactly the case a 200 OK cannot rule out.
     */
    public static function truncated(string $provider, string $partialContent): self
    {
        return new self(
            sprintf(
                'AI provider "%s" ended the stream after %d byte(s) without a terminal event; the response is incomplete',
                $provider,
                strlen($partialContent),
            ),
            $partialContent,
        );
    }

    /**
     * The provider sent an error event in the middle of the stream.
     */
    public static function providerError(string $provider, string $message): self
    {
        return new self(
            sprintf('AI provider "%s" reported an error mid-stream: %s', $provider, $message),
        );
    }

    /**
     * The connection could not be opened, or died while being read.
     */
    public static function transportFailure(string $provider, string $url, string $reason): self
    {
        return new self(
            sprintf('Failed to stream from AI provider "%s" at %s: %s', $provider, $url, $reason),
        );
    }

    /**
     * No further bytes arrived within the configured idle window.
     *
     * Total generation time is not capped: a long answer is not a failure. A
     * silent socket is.
     */
    public static function stalled(string $provider, int $idleTimeoutSeconds, string $partialContent): self
    {
        return new self(
            sprintf(
                'AI provider "%s" sent nothing for %d second(s); the stream is treated as dead',
                $provider,
                $idleTimeoutSeconds,
            ),
            $partialContent,
        );
    }

    /**
     * The provider answered with a non-2xx status before any stream body.
     */
    public static function httpError(string $provider, int $status, string $body): self
    {
        return new self(
            sprintf(
                'AI provider "%s" refused the stream with HTTP %d: %s',
                $provider,
                $status,
                substr($body, 0, 2000),
            ),
            code: $status,
        );
    }

    /**
     * A tool call's argument fragments did not reassemble into a JSON object.
     *
     * Handing a tool half its arguments is worse than handing it none, so this
     * fails the whole stream rather than emitting a degraded {@see \Pulsar\AI\ToolCall}.
     */
    public static function malformedToolCall(string $provider, string $name, string $raw): self
    {
        return new self(
            sprintf(
                'AI provider "%s" streamed tool call "%s" with arguments that are not a JSON object: %s',
                $provider,
                $name === '' ? '(unnamed)' : $name,
                substr($raw, 0, 500),
            ),
        );
    }

    /**
     * The stream ended by failing rather than by finishing, and the caller has
     * already handled that failure but is asking for the response anyway.
     *
     * Answering would mean producing a response for a stream that never reached
     * its terminal event, which is the one thing this type exists to prevent.
     */
    public static function didNotComplete(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'The "%s" stream ended without a complete response; its failure was reported when it happened',
                $provider,
            ),
            previous: $previous,
        );
    }

    /**
     * The same failure, carrying the text that had arrived when it happened.
     *
     * The transport knows the socket died but not what the parser had already
     * produced, so the provider re-throws through here once it can supply it.
     */
    public function withPartialContent(string $partialContent): self
    {
        if ($partialContent === '' || $this->partialContent !== '') {
            return $this;
        }

        return new self($this->getMessage(), $partialContent, $this->getCode(), $this);
    }
}
