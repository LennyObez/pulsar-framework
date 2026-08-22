<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Internal\Store;

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
    public function recordAndRetrieveProvenance(): void
    {
        $prov = $this->makeProvenance('ds-1', 'prov-1', consentObtained: true);
        $this->store->recordProvenance($prov);

        $records = $this->store->getProvenance('ds-1');

        self::assertCount(1, $records);
        self::assertSame('prov-1', $records[0]->id);
    }

    #[Test]
    public function multipleProvenanceRecordsPerDataset(): void
    {
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-1'));
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-2'));
        $this->store->recordProvenance($this->makeProvenance('ds-2', 'prov-3'));

        self::assertCount(2, $this->store->getProvenance('ds-1'));
        self::assertCount(1, $this->store->getProvenance('ds-2'));
    }

    #[Test]
    public function getProvenanceReturnsEmptyForUnknownDataset(): void
    {
        self::assertSame([], $this->store->getProvenance('nonexistent'));
    }

    #[Test]
    public function recordAndRetrieveQualityReport(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: 95.0,
            accuracy: 90.0,
            consistency: 85.0,
            totalRecords: 10000,
            invalidRecords: 50,
        );

        $this->store->recordQualityReport($report);

        $retrieved = $this->store->getLatestQualityReport('ds-1');

        self::assertNotNull($retrieved);
        self::assertSame(95.0, $retrieved->completeness);
        self::assertSame(10000, $retrieved->totalRecords);
    }

    #[Test]
    public function latestQualityReportOverwritesPrevious(): void
    {
        $old = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable('2025-01-01'),
            completeness: 50.0,
            accuracy: 50.0,
            consistency: 50.0,
            totalRecords: 100,
            invalidRecords: 50,
        );
        $new = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable('2025-06-01'),
            completeness: 95.0,
            accuracy: 90.0,
            consistency: 85.0,
            totalRecords: 10000,
            invalidRecords: 10,
        );

        $this->store->recordQualityReport($old);
        $this->store->recordQualityReport($new);

        $retrieved = $this->store->getLatestQualityReport('ds-1');
        self::assertNotNull($retrieved);
        self::assertSame(95.0, $retrieved->completeness);
    }

    #[Test]
    public function getLatestQualityReportReturnsNullForUnknown(): void
    {
        self::assertNull($this->store->getLatestQualityReport('nonexistent'));
    }

    #[Test]
    public function isConsentCompleteReturnsTrueWhenAllRecordsHaveConsent(): void
    {
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-1', consentObtained: true));
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-2', consentObtained: true));

        self::assertTrue($this->store->isConsentComplete('ds-1'));
    }

    #[Test]
    public function isConsentCompleteReturnsFalseWhenAnyRecordLacksConsent(): void
    {
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-1', consentObtained: true));
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-2', consentObtained: false));

        self::assertFalse($this->store->isConsentComplete('ds-1'));
    }

    #[Test]
    public function isConsentCompleteReturnsFalseForEmptyDataset(): void
    {
        self::assertFalse($this->store->isConsentComplete('nonexistent'));
    }

    #[Test]
    public function isConsentCompleteReturnsFalseWhenOnlyRecordLacksConsent(): void
    {
        $this->store->recordProvenance($this->makeProvenance('ds-1', 'prov-1', consentObtained: false));

        self::assertFalse($this->store->isConsentComplete('ds-1'));
    }

    /**
     * @param non-empty-string $datasetId
     * @param non-empty-string $id
     */
    private function makeProvenance(
        string $datasetId,
        string $id,
        bool $consentObtained = false,
    ): DataProvenance {
        return new DataProvenance(
            id: $id,
            datasetId: $datasetId,
            source: 'https://example.com/data',
            dataType: 'text',
            collectedAt: new DateTimeImmutable(),
            consentObtained: $consentObtained,
        );
    }
}
