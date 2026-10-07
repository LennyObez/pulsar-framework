<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testRecordAndRetrieveProvenance(): void
    {
        $provenance = new DataProvenance(
            id: 'prov-1',
            datasetId: 'ds-1',
            source: 'https://example.com/dataset',
            dataType: 'text',
            collectedAt: new DateTimeImmutable('2026-01-01'),
            consentObtained: true,
        );

        $this->store->recordProvenance($provenance);

        $records = $this->store->getProvenance('ds-1');
        self::assertCount(1, $records);
        self::assertSame('prov-1', $records[0]->id);
        self::assertTrue($records[0]->consentObtained);
    }

    public function testGetProvenanceReturnsEmptyForUnknownDataset(): void
    {
        self::assertSame([], $this->store->getProvenance('nonexistent'));
    }

    public function testMultipleProvenanceRecordsPerDataset(): void
    {
        $this->store->recordProvenance(new DataProvenance(
            id: 'p1',
            datasetId: 'ds-1',
            source: 'source-a',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
        ));
        $this->store->recordProvenance(new DataProvenance(
            id: 'p2',
            datasetId: 'ds-1',
            source: 'source-b',
            dataType: 'images',
            collectedAt: new DateTimeImmutable(),
        ));

        self::assertCount(2, $this->store->getProvenance('ds-1'));
    }

    public function testRecordAndRetrieveQualityReport(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable('2026-01-15'),
            completeness: 95.0,
            accuracy: 92.5,
            consistency: 88.0,
            totalRecords: 10000,
            invalidRecords: 150,
            issues: ['Missing values in column X'],
        );

        $this->store->recordQualityReport($report);

        $retrieved = $this->store->getLatestQualityReport('ds-1');
        self::assertNotNull($retrieved);
        self::assertSame(95.0, $retrieved->completeness);
        self::assertSame(150, $retrieved->invalidRecords);
    }

    public function testLatestQualityReportOverwritesPrevious(): void
    {
        $this->store->recordQualityReport(new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable('2026-01-01'),
            completeness: 80.0,
            accuracy: 80.0,
            consistency: 80.0,
            totalRecords: 5000,
            invalidRecords: 500,
        ));
        $this->store->recordQualityReport(new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable('2026-02-01'),
            completeness: 95.0,
            accuracy: 95.0,
            consistency: 95.0,
            totalRecords: 10000,
            invalidRecords: 50,
        ));

        $report = $this->store->getLatestQualityReport('ds-1');
        self::assertNotNull($report);
        self::assertSame(95.0, $report->completeness);
        self::assertSame(10000, $report->totalRecords);
    }

    public function testGetLatestQualityReportReturnsNullForUnknown(): void
    {
        self::assertNull($this->store->getLatestQualityReport('nonexistent'));
    }

    public function testIsConsentCompleteWhenAllRecordsHaveConsent(): void
    {
        $this->store->recordProvenance(new DataProvenance(
            id: 'p1',
            datasetId: 'ds-1',
            source: 'src',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
            consentObtained: true,
        ));
        $this->store->recordProvenance(new DataProvenance(
            id: 'p2',
            datasetId: 'ds-1',
            source: 'src2',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
            consentObtained: true,
        ));

        self::assertTrue($this->store->isConsentComplete('ds-1'));
    }

    public function testIsConsentIncompleteWhenAnyRecordMissesConsent(): void
    {
        $this->store->recordProvenance(new DataProvenance(
            id: 'p1',
            datasetId: 'ds-1',
            source: 'src',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
            consentObtained: true,
        ));
        $this->store->recordProvenance(new DataProvenance(
            id: 'p2',
            datasetId: 'ds-1',
            source: 'src2',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
            consentObtained: false,
        ));

        self::assertFalse($this->store->isConsentComplete('ds-1'));
    }

    public function testIsConsentCompleteFalseForEmptyDataset(): void
    {
        self::assertFalse($this->store->isConsentComplete('nonexistent'));
    }
}
