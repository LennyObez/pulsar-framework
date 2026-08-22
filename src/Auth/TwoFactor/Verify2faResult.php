<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of a TOTP code verification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Verify2faResult
{
    public function __construct(
        public bool $verified,
        public VerifyReason $reason,
        public TwoFactorPurpose $purpose,
        public ?int $acceptedTimeStep = null,
    ) {}

    public static function success(TwoFactorPurpose $purpose, int $acceptedTimeStep): self
    {
        return new self(true, VerifyReason::Valid, $purpose, $acceptedTimeStep);
    }

    public static function failure(VerifyReason $reason, TwoFactorPurpose $purpose): self
    {
        return new self(false, $reason, $purpose);
    }
}
