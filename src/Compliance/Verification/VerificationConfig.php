<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            bootCheck: Coerce::strictBool($data['boot_check'] ?? null, true),
            evidenceIntervalSeconds: Coerce::int($data['evidence_interval'] ?? null, 3600),
            strictMode: Coerce::strictBool($data['strict_mode'] ?? null),
        );
    }
}
