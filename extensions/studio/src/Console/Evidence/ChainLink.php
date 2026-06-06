<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use Pulsar\Api\Internal;

/**
 * Represents a single link in the evidence hash chain.
 */
#[Internal]
final readonly class ChainLink
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $eventId,
        public string $previousHash,
        public string $currentHash,
        public ?string $linkMac,
    ) {}
}
