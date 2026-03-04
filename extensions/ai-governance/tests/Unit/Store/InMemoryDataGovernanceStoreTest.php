<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore;

#[CoversClass(InMemoryDataGovernanceStore::class)]
final class InMemoryDataGovernanceStoreTest extends TestCase
{
    private InMemoryDataGovernanceStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryDataGovernanceStore();
    }

    #[Test]
    public function getProvenanceReturnsEmptyForUnknown(): void
    {
        self::assertSame([], $this->store->getProvenance('ds-unknown'));
    }

    #[Test]
    public function recordAndGetProvenance(): void
    {
        $provenance = $this->createProvenance('prov-1', 'ds-1');
        $this->store->recordProvenance($provenance);

        $records = $this->store->getProvenance('ds-1');
        self::assertCount(1, $records);
        self::assertSame('prov-1', $records[0]->id);
    }

    #[Test]
    public function multipleProvenanceRecordsPerDataset(): void
    {
        $this->store->recordProvenance($this->createProvenance('prov-1', 'ds-1'));
        $this->store->recordProvenance($this->createProvenance('prov-2', 'ds-1'));

        self::assertCount(2, $this->store->getProvenance('ds-1'));
    }

    #[Test]
    public function provenanceIsScopedToDataset(): void
    {
        $this->store->recordProvenance($this->createProvenance('prov-1', 'ds-1'));
        $this->store->recordProvenance($this->createProvenance('prov-2', 'ds-2'));

        self::assertCount(1, $this->store->getProvenance('ds-1'));
        self::assertCount(1, $this->store->getProvenance('ds-2'));
    }

    #[Test]
    public function getLatestQualityReportReturnsNullForUnknown(): void
    {
        self::assertNull($this->store->getLatestQualityReport('ds-unknown'));
    }

    #[Test]
    public function recordAndGetQualityReport(): void
    {
        $report = $this->createReport('ds-1');
        $this->store->recordQualityReport($report);

        $found = $this->store->getLatestQualityReport('ds-1');
        self::assertNotNull($found);
        self::assertSame('ds-1', $found->datasetId);
    }

    #[Test]
    public function latestReportOverwritesPrevious(): void
    {
        $this->store->recordQualityReport($this->createReport('ds-1', completeness: 50.0));
        $this->store->recordQualityReport($this->createReport('ds-1', completeness: 99.0));

        $latest = $this->store->getLatestQualityReport('ds-1');
        self::assertSame(99.0, $latest->completeness);
    }

    #[Test]
    public function isConsentCompleteReturnsFalseForUnknown(): void
    {
        self::assertFalse($this->store->isConsentComplete('ds-unknown'));
    }

    #[Test]
    public function isConsentCompleteReturnsFalseWhenNotAllConsented(): void
    {
        $this->store->recordProvenance($this->createProvenance('p1', 'ds-1', consentObtained: true));
        $this->store->recordProvenance($this->createProvenance('p2', 'ds-1', consentObtained: false));

        self::assertFalse($this->store->isConsentComplete('ds-1'));
    }

    #[Test]
    public function isConsentCompleteReturnsTrueWhenAllConsented(): void
    {
        $this->store->recordProvenance($this->createProvenance('p1', 'ds-1', consentObtained: true));
        $this->store->recordProvenance($this->createProvenance('p2', 'ds-1', consentObtained: true));

        self::assertTrue($this->store->isConsentComplete('ds-1'));
    }

    private function createProvenance(
        string $id,
        string $datasetId,
        bool $consentObtained = false,
    ): DataProvenance {
        return new DataProvenance(
            id: $id,
            datasetId: $datasetId,
            source: 'internal-db',
            dataType: 'structured',
            collectedAt: new DateTimeImmutable('2026-01-01'),
            consentObtained: $consentObtained,
        );
    }

    private function createReport(string $datasetId, float $completeness = 95.0): DataQualityReport
    {
        return new DataQualityReport(
            datasetId: $datasetId,
            assessedAt: new DateTimeImmutable('2026-01-15'),
            completeness: $completeness,
            accuracy: 90.0,
            consistency: 85.0,
            totalRecords: 10000,
            invalidRecords: 50,
        );
    }
}
