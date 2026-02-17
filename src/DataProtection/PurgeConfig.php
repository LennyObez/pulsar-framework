<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;

/**
 * Purge subsystem configuration.
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
     * @param array<mixed, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawBatchSize = $data['batch_size'] ?? 1000;
        $rawAudit = $data['audit_purge_operations'] ?? true;
        $rawDryRun = $data['dry_run'] ?? false;

        return new self(
            batchSize: is_int($rawBatchSize) ? $rawBatchSize : 1000,
            auditPurgeOperations: is_bool($rawAudit) ? $rawAudit : true,
            dryRun: is_bool($rawDryRun) ? $rawDryRun : false,
        );
    }
}
