<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of session hijacking analysis.
 */
#[Api(since: '1.0.0')]
final readonly class HijackVerdict
{
    private function __construct(
        public HijackAction $action,
        public string $reason,
    ) {}

    #[NoDiscard]
    public static function ok(): self
    {
        return new self(HijackAction::Allow, '');
    }

    #[NoDiscard]
    public static function invalidate(string $reason): self
    {
        return new self(HijackAction::Invalidate, $reason);
    }

    #[NoDiscard]
    public static function challenge(string $reason): self
    {
        return new self(HijackAction::Challenge, $reason);
    }

    #[NoDiscard]
    public static function warn(string $reason): self
    {
        return new self(HijackAction::Warn, $reason);
    }

    public function isOk(): bool
    {
        return $this->action === HijackAction::Allow;
    }

    public function requiresInvalidation(): bool
    {
        return $this->action === HijackAction::Invalidate;
    }
}
