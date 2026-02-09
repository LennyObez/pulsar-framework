<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of a recovery code consume operation.
 */
#[Api(since: '1.0.0')]
final readonly class ConsumeResult
{
    public function __construct(
        public bool $consumed,
        public int $codeIndex,
        public ConsumeReason $reason,
    ) {}

    public static function success(int $codeIndex): self
    {
        return new self(true, $codeIndex, ConsumeReason::Consumed);
    }

    public static function failure(ConsumeReason $reason): self
    {
        return new self(false, -1, $reason);
    }
}
