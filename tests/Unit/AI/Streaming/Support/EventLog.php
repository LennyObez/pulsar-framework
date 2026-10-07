<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming\Support;

/**
 * An ordered record of what happened, and when relative to everything else.
 *
 * The interleaving tests need one list written from two places — the transport
 * as it hands over a chunk, and the consumer as it receives a delta — because
 * the ORDER of those two is the claim. A by-reference closure would do it, but
 * nothing can follow a by-reference write into a closure, so the collector is an
 * object and the writes are ordinary method calls.
 */
final class EventLog
{
    /** @var list<string> */
    private array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return $this->events;
    }
}
