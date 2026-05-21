<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Internal;

use Pulsar\Api\Internal;

/**
 * Result of a WebSocket handshake validation.
 */
#[Internal]
final readonly class HandshakeResult
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private function __construct(
        public bool $accepted,
        public string $acceptKey,
        public string $rejectionReason,
    ) {}

    public static function accepted(string $acceptKey): self
    {
        return new self(true, $acceptKey, '');
    }

    public static function rejected(string $reason): self
    {
        return new self(false, '', $reason);
    }
}
