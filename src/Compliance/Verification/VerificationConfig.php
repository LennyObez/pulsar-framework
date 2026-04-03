<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     enabled?: bool,
     *     boot_check?: bool,
     *     evidence_interval?: int,
     *     strict_mode?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            bootCheck: $data['boot_check'] ?? true,
            evidenceIntervalSeconds: $data['evidence_interval'] ?? 3600,
            strictMode: $data['strict_mode'] ?? false,
        );
    }
}
