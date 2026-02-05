<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Evidence;

use Pulsar\Api\Internal;

/**
 * Represents a single link in the evidence hash chain.
 */
#[Internal]
final readonly class ChainLink
{
    public function __construct(
        public string $eventId,
        public string $previousHash,
        public string $currentHash,
        public ?string $linkMac,
    ) {}
}
