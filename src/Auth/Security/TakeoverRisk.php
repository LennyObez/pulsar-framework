<?php

declare(strict_types=1);

namespace Pulsar\Auth\Security;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Risk assessment result from the account takeover guard.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TakeoverRisk
{
    private function __construct(
        public TakeoverRiskLevel $level,
        public string $reason,
    ) {}

    #[NoDiscard]
    public static function low(): self
    {
        return new self(TakeoverRiskLevel::Low, '');
    }

    #[NoDiscard]
    public static function elevated(string $reason): self
    {
        return new self(TakeoverRiskLevel::Elevated, $reason);
    }

    #[NoDiscard]
    public static function high(string $reason): self
    {
        return new self(TakeoverRiskLevel::High, $reason);
    }

    public function isLow(): bool
    {
        return $this->level === TakeoverRiskLevel::Low;
    }

    public function isElevatedOrHigher(): bool
    {
        return $this->level !== TakeoverRiskLevel::Low;
    }
}
