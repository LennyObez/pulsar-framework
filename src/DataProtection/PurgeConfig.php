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
    private const array KNOWN_KEYS = ['batch_size', 'audit_purge_operations', 'dry_run', 'schedule'];

    /**
     * Cron expression the scheduled purge runs on when the operator names none.
     *
     * 03:00 daily: retention deletion is I/O the deployment should not be doing
     * during its busy hours, and a daily cadence is finer than every retention
     * period the shipped config declares (the shortest is 90 days).
     */
    public const string DEFAULT_SCHEDULE = '0 3 * * *';

    /**
     * @param string $schedule Cron expression for {@see \Pulsar\DataProtection\DataPurgeJob}.
     *     An empty string unregisters the job, leaving retention to
     *     `pulsar data:purge` alone — the one way to have the policies and no
     *     automatic deletion, chosen explicitly rather than by omission.
     * @param list<string> $unknownKeys Keys present in the raw `purge` array that this
     *     DTO does not read — a misspelled `dry_run` silently runs a real purge where
     *     the operator meant a dry run.
     */
    public function __construct(
        public int $batchSize = 1000,
        public bool $auditPurgeOperations = true,
        public bool $dryRun = false,
        public string $schedule = self::DEFAULT_SCHEDULE,
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
            schedule: Coerce::string($data['schedule'] ?? null, self::DEFAULT_SCHEDULE),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
