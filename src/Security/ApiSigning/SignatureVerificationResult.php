<?php

declare(strict_types=1);

namespace Pulsar\Security\ApiSigning;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of an API request signature verification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignatureVerificationResult
{
    private function __construct(
        public bool $valid,
        public string $reason,
    ) {}

    #[NoDiscard]
    public static function success(): self
    {
        return new self(valid: true, reason: '');
    }

    #[NoDiscard]
    public static function failure(string $reason): self
    {
        return new self(valid: false, reason: $reason);
    }
}
