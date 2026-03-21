<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Result of a CMS data export operation.
 *
 * Contains the exported data, an evidence hash for integrity verification,
 * and metadata about what was exported and how.
 *
 * @psalm-api Public DTO returned from ImportExportServiceInterface::export();
 *            consumed by export controllers and the framework's
 *            ImportExportProvider adapter.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExportBundle
{
    /**
     * @param array<string, mixed> $data Exported entities keyed by type
     * @param string $evidenceHash BLAKE2b hash of the serialized JSON for tamper detection
     * @param DateTimeImmutable $createdAt When the export was generated
     * @param list<string> $scope Entity types included in this export
     * @param bool $piiIncluded Whether PII fields were included (not redacted)
     */
    public function __construct(
        public array $data,
        public string $evidenceHash,
        public DateTimeImmutable $createdAt,
        public array $scope,
        public bool $piiIncluded,
    ) {}
}
