<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of a 2FA setup confirmation.
 */
#[Api(since: '1.0.0')]
final readonly class Confirm2faSetupResult
{
    public function __construct(
        public bool $confirmed,
        public VerifyReason $reason,
    ) {}

    public static function success(): self
    {
        return new self(true, VerifyReason::Valid);
    }

    public static function failure(VerifyReason $reason): self
    {
        return new self(false, $reason);
    }
}
