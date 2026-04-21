<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     batch_size?: int,
     *     audit_purge_operations?: bool,
     *     dry_run?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            batchSize: $data['batch_size'] ?? 1000,
            auditPurgeOperations: $data['audit_purge_operations'] ?? true,
            dryRun: $data['dry_run'] ?? false,
        );
    }
}
