<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress\Support;

use function count;
use function str_contains;

/**
 * Everything that reached a transport, as the bytes it would have sent.
 *
 * The egress claims are all of the form "these bytes did / did not leave", and
 * the only place that can be observed is the last object before the socket. A
 * double that recorded the *arguments* to `chat()` would prove something weaker
 * and easier: that the guard rewrote a PHP object. This records the serialised
 * request body the provider built from that object, which is the thing the
 * endpoint would actually receive.
 *
 * One recorder is shared by the HTTP double and the streaming double so that the
 * streamed and unstreamed assertions are written against the same evidence, and
 * an empty recorder means the same thing on both paths: nothing was sent.
 */
final class WireRecorder
{
    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    public function record(string $url, string $body): void
    {
        $this->requests[] = ['url' => $url, 'body' => $body];
    }

    /**
     * @return list<array{url: string, body: string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function sentNothing(): bool
    {
        return $this->requests === [];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The body of the only request made.
     *
     * Deliberately asserts singularity by returning '' for anything else, so a
     * test that expected one request and got none reads as an empty body rather
     * than as an index error somewhere unrelated.
     */
    public function onlyBody(): string
    {
        return count($this->requests) === 1 ? $this->requests[0]['body'] : '';
    }

    /**
     * Whether any byte sequence anywhere in what was sent contains $needle.
     *
     * The negative form is the one that matters: proving a value did NOT go out
     * has to look at every request, not just the one the test was thinking of.
     */
    public function anyBodyContains(string $needle): bool
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['body'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
