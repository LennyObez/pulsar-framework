<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;

/**
 * Contract for running and rolling back migrations.
 * @api
 */
#[Api(since: '1.0.0')]
interface MigrationRunnerInterface
{
    /**
     * Ensure the migration tracking table exists.
     */
    public function ensureMigrationTable(): void;

    /**
     * Run all pending migrations.
     *
     * @return list<string> List of applied version strings.
     */
    public function runPending(): array;

    /**
     * Rollback the last batch of migrations.
     *
     * @return list<string> List of rolled-back version strings.
     */
    public function rollbackLastBatch(): array;
}
