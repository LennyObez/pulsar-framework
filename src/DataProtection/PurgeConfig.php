<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

/**
 * Purge subsystem configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PurgeConfig implements ReportsUnknownKeys
{
    /** Keys read from the `purge` sub-array of config/data_protection.php. */
    private const array KNOWN_KEYS = ['batch_size', 'audit_purge_operations', 'dry_run'];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `purge` array that this
     *     DTO does not read — a misspelled `dry_run` silently runs a real purge where
     *     the operator meant a dry run.
     */
    public function __construct(
        public int $batchSize = 1000,
        public bool $auditPurgeOperations = true,
        public bool $dryRun = false,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
