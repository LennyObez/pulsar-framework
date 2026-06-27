<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use Pulsar\Api\Internal;

/**
 * Per-tick counters returned by {@see OutboxRelay::tick()}.
 *
 * Exposed so callers (worker loop, CLI command, supervisor) can
 * emit metrics or apply back-pressure without re-deriving the
 * counts from the pending table.
 */
#[Internal]
final readonly class OutboxRelayTickResult
{
    public function __construct(
        public int $attempted,
        public int $published,
        public int $failed,
        public int $deadLettered = 0,
    ) {}
}
