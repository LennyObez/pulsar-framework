<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Purge subsystem configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PurgeConfig
{
    public function __construct(
        public int $batchSize = 1000,
        public bool $auditPurgeOperations = true,
        public bool $dryRun = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            batchSize: Coerce::int($data['batch_size'] ?? null, 1000),
            auditPurgeOperations: Coerce::strictBool($data['audit_purge_operations'] ?? null, true),
            dryRun: Coerce::strictBool($data['dry_run'] ?? null),
        );
    }
}
