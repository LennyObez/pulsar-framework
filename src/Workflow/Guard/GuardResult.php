<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Guard;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a transition guard evaluation.
 *
 * Guards produce either an allow or deny result. Deny results include
 * a human-readable reason that is logged for audit purposes.
 */
#[Api(since: '1.0.0')]
final readonly class GuardResult
{
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {}

    /**
     * The guard allows the transition.
     */
    #[NoDiscard]
    public static function allow(): self
    {
        return new self(allowed: true, reason: '');
    }

    /**
     * The guard denies the transition with a reason.
     */
    #[NoDiscard]
    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return !$this->allowed;
    }
}
