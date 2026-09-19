<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;

/**
 * Training-data provenance, quality and consent, held where they outlive the
 * process that recorded them.
 *
 * ISO 42001:2023 Clause 8.3 and Annex A.5 ask an organisation to document where
 * the data used to build an AI system came from, what state it is in, and the
 * basis on which it was obtained. All three are claims about the past, and
 * {@see InMemoryDataGovernanceStore} can only answer them for the request that
 * made them: consent recorded by one worker is unknown to the next, so
 * `isConsentComplete()` — which the consent-enforcing decorator and the
 * deployment path both read — answers false for a dataset whose consent was
 * properly recorded last week.
 *
 * PROVENANCE IS KEYED BY ITS RECORD ID, which {@see DataProvenance} documents as
 * a "unique provenance record identifier", and a dataset accumulates as many rows
 * as it has provenance records. That is the shape of the domain: one dataset is
 * assembled from several sources, each with its own licence and its own consent
 * position, and `isConsentComplete()` is the question of whether every one of them
 * carries consent. Re-recording the same record id corrects that record;
 * {@see InMemoryDataGovernanceStore} appends a second copy, which would let a
 * replayed import turn one un-consented source into two.
 *
 * QUALITY REPORTS ARE KEYED BY DATASET, because the contract asks for the LATEST
 * report and offers no way to name an older one. Storing a history the contract
 * cannot express would retain personal-data-adjacent statistics nobody can read
 * back, which Clause 7.5.3 asks the opposite of.
 */
#[Internal(reason: 'Durable data governance store; use AiDataGovernanceInterface for access')]
final readonly class DbDataGovernanceStore implements AiDataGovernanceInterface
{
    private const string PROVENANCE = 'ai_data_provenance';

    private const string REPORTS = 'ai_data_quality_reports';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function recordProvenance(DataProvenance $provenance): void
    {
        $insert = 'INSERT INTO ' . self::PROVENANCE
            . ' (id, dataset_id, source, data_type, collected_at, consent_obtained, consent_reference,'
            . ' license, transformations, quality_metrics)'
            . ' VALUES (:id, :dataset_id, :source, :data_type, :collected_at, :consent_obtained,'
            . ' :consent_reference, :license, :transformations, :quality_metrics)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['id'],
                [
                    'dataset_id',
                    'source',
                    'data_type',
                    'collected_at',
                    'consent_obtained',
                    'consent_reference',
                    'license',
                    'transformations',
                    'quality_metrics',
                ],
            ),
            [
                'id' => $provenance->id,
                'dataset_id' => $provenance->datasetId,
                'source' => $provenance->source,
                'data_type' => $provenance->dataType,
                'collected_at' => StoredValue::instantToStore($provenance->collectedAt),
                'consent_obtained' => $provenance->consentObtained ? 1 : 0,
                'consent_reference' => $provenance->consentReference,
                'license' => $provenance->license,
                'transformations' => StoredValue::encode($provenance->transformations),
                'quality_metrics' => StoredValue::encode($provenance->qualityMetrics),
            ],
        );
    }

    #[Override]
    public function getProvenance(string $datasetId): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::PROVENANCE . ' WHERE dataset_id = :dataset_id'
                . ' ORDER BY collected_at ASC, id ASC',
            ['dataset_id' => $datasetId],
        )->map(fn(Row $row): DataProvenance => $this->hydrateProvenance($row));
    }

    #[Override]
    public function recordQualityReport(DataQualityReport $report): void
    {
        $insert = 'INSERT INTO ' . self::REPORTS
            . ' (dataset_id, assessed_at, completeness, accuracy, consistency, total_records,'
            . ' invalid_records, issues)'
            . ' VALUES (:dataset_id, :assessed_at, :completeness, :accuracy, :consistency,'
            . ' :total_records, :invalid_records, :issues)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['dataset_id'],
                [
                    'assessed_at',
                    'completeness',
                    'accuracy',
                    'consistency',
                    'total_records',
                    'invalid_records',
                    'issues',
                ],
            ),
            [
                'dataset_id' => $report->datasetId,
                'assessed_at' => StoredValue::instantToStore($report->assessedAt),
                'completeness' => $report->completeness,
                'accuracy' => $report->accuracy,
                'consistency' => $report->consistency,
                'total_records' => $report->totalRecords,
                'invalid_records' => $report->invalidRecords,
                'issues' => StoredValue::encode($report->issues),
            ],
        );
    }

    #[Override]
    public function getLatestQualityReport(string $datasetId): ?DataQualityReport
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::REPORTS . ' WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        )->first();

        return $row === null ? null : $this->hydrateReport($row);
    }

    #[Override]
    public function isConsentComplete(string $datasetId): bool
    {
        $rows = $this->connection->query(
            'SELECT consent_obtained FROM ' . self::PROVENANCE . ' WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        );

        // A dataset with no provenance record answers false, matching the
        // in-memory store and matching the clause: Article 10 and Clause 8.3 ask
        // for the basis on which data was obtained, and "nothing is recorded" is
        // not a basis. Answering true for an empty set would make an untracked
        // dataset indistinguishable from a fully consented one.
        if ($rows->isEmpty()) {
            return false;
        }

        foreach ($rows->map(static fn(Row $row): bool => $row->getBool('consent_obtained')) as $obtained) {
            if (! $obtained) {
                return false;
            }
        }

        return true;
    }

    private function hydrateProvenance(Row $row): DataProvenance
    {
        return new DataProvenance(
            id: StoredValue::required($row->getString('id'), self::PROVENANCE, 'id'),
            datasetId: StoredValue::required($row->getString('dataset_id'), self::PROVENANCE, 'dataset_id'),
            source: StoredValue::required($row->getString('source'), self::PROVENANCE, 'source'),
            dataType: StoredValue::required($row->getString('data_type'), self::PROVENANCE, 'data_type'),
            collectedAt: StoredValue::instant($row->getString('collected_at'), self::PROVENANCE, 'collected_at'),
            consentObtained: $row->getBool('consent_obtained'),
            consentReference: StoredValue::optional($row->getNullableString('consent_reference')),
            license: StoredValue::optional($row->getNullableString('license')),
            transformations: StoredValue::stringList(
                $row->getString('transformations'),
                self::PROVENANCE,
                'transformations',
            ),
            qualityMetrics: StoredValue::map(
                $row->getString('quality_metrics'),
                self::PROVENANCE,
                'quality_metrics',
            ),
        );
    }

    private function hydrateReport(Row $row): DataQualityReport
    {
        return new DataQualityReport(
            datasetId: StoredValue::required($row->getString('dataset_id'), self::REPORTS, 'dataset_id'),
            assessedAt: StoredValue::instant($row->getString('assessed_at'), self::REPORTS, 'assessed_at'),
            completeness: $row->getFloat('completeness'),
            accuracy: $row->getFloat('accuracy'),
            consistency: $row->getFloat('consistency'),
            totalRecords: $row->getInt('total_records'),
            invalidRecords: $row->getInt('invalid_records'),
            issues: StoredValue::stringList($row->getString('issues'), self::REPORTS, 'issues'),
        );
    }
}
