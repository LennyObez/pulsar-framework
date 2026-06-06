<?php

declare(strict_types=1);

namespace Pulsar\Event\Contract;

use Pulsar\Api\Internal;
use Pulsar\Event\EventEnvelope;

/**
 * Serializes and deserializes event envelopes for transport/storage.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Unimplemented port; will be promoted to #[Api] when an adapter ships')]
interface EventSerializer
{
    /**
     * Serialize an event envelope to a string representation.
     */
    public function serialize(EventEnvelope $envelope): string;

    /**
     * Deserialize a string representation back into an event envelope.
     */
    public function deserialize(string $data): EventEnvelope;
}
