<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Evidence\InMemoryEvidenceExporter;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;

use function hash;
use function json_encode;
use function strlen;

#[CoversClass(InMemoryEvidenceExporter::class)]
final class InMemoryEvidenceExporterTest extends TestCase
{
    private InMemoryEvidenceExporter $exporter;
    private RetentionPolicy $policy;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryEvidenceExporter();
        $this->policy = new RetentionPolicy(
            policyId: 'test-1',
            version: 1,
            regulation: 'SOX',
            retentionPeriodDays: 2555,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'Test policy',
        );
    }

    #[Test]
    public function exportReturnsResultWithCorrectRecordCount(): void
    {
        $records = [
            ['id' => '1', 'data' => 'first'],
            ['id' => '2', 'data' => 'second'],
            ['id' => '3', 'data' => 'third'],
        ];

        $result = $this->exporter->export($records, $this->policy, 'admin');

        self::assertSame(3, $result->recordCount);
    }

    #[Test]
    public function exportGeneratesNonEmptyArchiveId(): void
    {
        $result = $this->exporter->export([], $this->policy, 'admin');

        self::assertNotEmpty($result->archiveId);
        // Archive ID should be 32 hex chars (16 bytes)
        self::assertSame(32, strlen($result->archiveId));
    }

    #[Test]
    public function exportGeneratesUniqueArchiveIds(): void
    {
        $result1 = $this->exporter->export([], $this->policy, 'admin');
        $result2 = $this->exporter->export([], $this->policy, 'admin');

        self::assertNotSame($result1->archiveId, $result2->archiveId);
    }

    #[Test]
    public function exportGeneratesHashManifest(): void
    {
        $records = [['id' => '1', 'value' => 'test']];

        $result = $this->exporter->export($records, $this->policy, 'admin');

        $encoded = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        $expectedHash = hash('sha256', $encoded);
        self::assertSame($expectedHash, $result->hashManifest);
    }

    #[Test]
    public function exportReportsNotEncrypted(): void
    {
        $result = $this->exporter->export([], $this->policy, 'admin');

        self::assertFalse($result->encrypted);
    }

    #[Test]
    public function exportSetsExportedAtTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $result = $this->exporter->export([], $this->policy, 'admin');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $result->exportedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $result->exportedAt->getTimestamp());
    }

    #[Test]
    public function allExportsReturnsAccumulatedResults(): void
    {
        $this->exporter->export([['a' => '1']], $this->policy, 'admin');
        $this->exporter->export([['b' => '2']], $this->policy, 'admin');

        self::assertCount(2, $this->exporter->allExports());
    }

    #[Test]
    public function allExportedRecordsReturnsRecordSets(): void
    {
        $records1 = [['id' => '1']];
        $records2 = [['id' => '2'], ['id' => '3']];

        $this->exporter->export($records1, $this->policy, 'admin');
        $this->exporter->export($records2, $this->policy, 'admin');

        $allRecords = $this->exporter->allExportedRecords();

        self::assertCount(2, $allRecords);
        self::assertSame($records1, $allRecords[0]);
        self::assertSame($records2, $allRecords[1]);
    }

    #[Test]
    public function exportHandlesEmptyRecordSet(): void
    {
        $result = $this->exporter->export([], $this->policy, 'admin');

        self::assertSame(0, $result->recordCount);
        self::assertNotEmpty($result->hashManifest);
    }

    #[Test]
    public function hashManifestIsDeterministic(): void
    {
        $records = [['id' => '1', 'data' => 'test']];

        $exporter1 = new InMemoryEvidenceExporter();
        $exporter2 = new InMemoryEvidenceExporter();

        $result1 = $exporter1->export($records, $this->policy, 'admin');
        $result2 = $exporter2->export($records, $this->policy, 'admin');

        self::assertSame($result1->hashManifest, $result2->hashManifest);
    }
}
