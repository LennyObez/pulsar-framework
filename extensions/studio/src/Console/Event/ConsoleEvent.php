<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event;

use Pulsar\Api\Internal;

/**
 * Contract for Studio Console events.
 */
#[Internal]
interface ConsoleEvent
{
    public function eventType(): EventType;

    public function schemaVersion(): EventVersion;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
