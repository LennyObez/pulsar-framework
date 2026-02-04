<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Result of a recovery code rotation operation.
 */
#[Api(since: '1.0.0')]
final readonly class RecoveryCodeRotationResult
{
    /**
     * @param RecoveryCodeSet $set The new hashed recovery code set
     * @param list<string> $plaintextCodes The new plaintext codes (show once, then discard)
     */
    public function __construct(
        public RecoveryCodeSet $set,
        public array $plaintextCodes,
    ) {}
}
