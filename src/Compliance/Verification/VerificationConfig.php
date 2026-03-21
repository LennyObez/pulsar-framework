<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;

/**
 * Configuration for the compliance verification engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VerificationConfig
{
    public function __construct(
        public bool $enabled = true,
        public bool $bootCheck = true,
        public int $evidenceIntervalSeconds = 3600,
        public bool $strictMode = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = isset($data['enabled']) && is_bool($data['enabled']) ? $data['enabled'] : true;
        $bootCheck = isset($data['boot_check']) && is_bool($data['boot_check']) ? $data['boot_check'] : true;
        $evidenceInterval = isset($data['evidence_interval']) && is_int($data['evidence_interval']) ? $data['evidence_interval'] : 3600;
        $strictMode = isset($data['strict_mode']) && is_bool($data['strict_mode']) ? $data['strict_mode'] : false;

        return new self(
            enabled: $enabled,
            bootCheck: $bootCheck,
            evidenceIntervalSeconds: $evidenceInterval,
            strictMode: $strictMode,
        );
    }
}
