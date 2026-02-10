<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Evidence;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;

/**
 * Contract for exporting compliance evidence archives.
 *
 * Implementations produce tamper-evident exports with integrity
 * hash manifests for regulatory audit purposes.
 */
#[Api(since: '1.0.0')]
interface EvidenceExporterInterface
{
    /**
     * Export records as a compliance evidence archive.
     *
     * @param list<array<string, mixed>> $records
     */
    public function export(
        array $records,
        RetentionPolicy $policy,
        string $operatorIdentity,
    ): EvidenceExportResult;
}
