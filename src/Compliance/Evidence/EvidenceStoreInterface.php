<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Pulsar\Api\Api;

/**
 * Persistence layer for compliance evidence records.
 * @api
 */
#[Api(since: '1.0.0')]
interface EvidenceStoreInterface
{
    /**
     * Store an evidence record.
     */
    public function store(EvidenceRecord $record): void;

    /**
     * Retrieve all evidence for a specific control.
     *
     * @return list<EvidenceRecord>
     */
    public function forControl(string $controlId): array;

    /**
     * Retrieve all evidence records.
     *
     * @return list<EvidenceRecord>
     */
    public function all(): array;

    /**
     * Retrieve a specific evidence record by ID.
     */
    public function get(string $id): ?EvidenceRecord;

    /**
     * Count evidence records for a control.
     */
    public function countForControl(string $controlId): int;
}
