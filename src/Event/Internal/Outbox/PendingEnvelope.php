<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal\Outbox;

use Pulsar\Api\Internal;
use Pulsar\Event\EventEnvelope;

/**
 * Pairs a pending outbox envelope with its current publish-attempt count.
 *
 * Used only between {@see DatabaseOutboxPort::pendingForRelay()} and
 * {@see OutboxRelay::tick()} so the relay can tell, with no extra query,
 * whether a failure pushes an envelope over its attempt budget — without
 * widening the public {@see EventEnvelope} with a persistence-only field.
 */
#[Internal]
final readonly class PendingEnvelope
{
    public function __construct(
        public EventEnvelope $envelope,
        public int $publishAttempts,
    ) {}
}
