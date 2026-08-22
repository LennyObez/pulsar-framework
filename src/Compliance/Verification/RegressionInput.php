<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * Input DTO for regression detection: captures current system configuration values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RegressionInput
{
    public function __construct(
        public int $sessionIdleTimeout,
        public int $passwordMinLength,
        public int $hstsMaxAge,
        public bool $encryptionAtRest,
        public string $mfaScope,
        public bool $tamperEvidentAudit,
    ) {}
}
