<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function count;
use function hash;
use function json_encode;

/**
 * In-memory evidence exporter for testing and development.
 *
 * Stores exports in memory and generates SHA-256 hash manifests for
 * integrity verification. Reports encrypted=false since no real
 * encryption is applied.
 */
#[Internal(reason: 'Test/dev evidence exporter implementation')]
final class InMemoryEvidenceExporter implements EvidenceExporterInterface
{
    /**
     * @var list<EvidenceExportResult>
     */
    private array $exports = [];

    /**
     * @var list<list<array<string, mixed>>>
     */
    private array $exportedRecords = [];

    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function export(
        array $records,
        RetentionPolicy $policy,
        string $operatorIdentity,
    ): EvidenceExportResult {
        $archiveId = bin2hex($this->randomizer->getBytes(16));

        $serialized = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hashManifest = hash('sha256', $serialized !== false ? $serialized : '[]');

        $result = new EvidenceExportResult(
            archiveId: $archiveId,
            recordCount: count($records),
            hashManifest: $hashManifest,
            operatorIdentity: $operatorIdentity,
            exportedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            encrypted: false,
        );

        $this->exports[] = $result;
        $this->exportedRecords[] = $records;

        return $result;
    }

    /**
     * @return list<EvidenceExportResult>
     */
    public function allExports(): array
    {
        return $this->exports;
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    public function allExportedRecords(): array
    {
        return $this->exportedRecords;
    }
}
