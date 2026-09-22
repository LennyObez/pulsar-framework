<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress\Support;

use Generator;
use Override;
use Pulsar\AI\Streaming\StreamTransportInterface;

/**
 * A {@see StreamTransportInterface} that records the request body into the
 * shared {@see WireRecorder} before replaying scripted frames.
 *
 * `$callCount` is the load-bearing field for the destination tests. The
 * providers build a streamed request eagerly but only call `postStream()` when
 * the generator is first advanced, so "the transport was never called" and "no
 * bytes were sent" are the same statement on this path — and a guard that
 * refused only on iteration would leave a request object built and held.
 */
final class RecordingStreamTransport implements StreamTransportInterface
{
    public int $callCount = 0;

    /**
     * @param list<string> $frames Body pieces to emit, in order
     */
    public function __construct(
        private readonly WireRecorder $recorder,
        private readonly array $frames = [],
    ) {}

    #[Override]
    public function postStream(string $url, string $body, array $headers, int $idleTimeoutSeconds): iterable
    {
        ++$this->callCount;
        $this->recorder->record($url, $body);

        return $this->emit();
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    private function emit(): Generator
    {
        foreach ($this->frames as $frame) {
            yield $frame;
        }
    }
}
