<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Post-Market Surveillance (PMS) report per MDR Articles 83-86.
 *
 * Documents systematic proactive collection and review of experience
 * gained from devices placed on the market.
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Articles 83-86)
 */
#[Api(since: '1.0.0')]
final readonly class PostMarketSurveillanceReport
{
    /**
     * @param list<string>                    $dataSourceDescriptions  Sources of PMS data
     * @param list<AdverseEventSummary>       $adverseEvents           Summary of adverse events
     * @param list<string>                    $correctiveActions       Field safety corrective actions taken
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public string $reportPeriodStart,
        public string $reportPeriodEnd,
        public DateTimeImmutable $reportDate,
        public string $manufacturer,
        public array $dataSourceDescriptions = [],
        public ?int $totalUnitsDistributed = null,
        public ?int $totalComplaintsReceived = null,
        public array $adverseEvents = [],
        public ?string $trendAnalysisSummary = null,
        public array $correctiveActions = [],
        public ?string $conclusion = null,
        public ReportType $reportType = ReportType::PmsReport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'report_period_start' => $this->reportPeriodStart,
            'report_period_end' => $this->reportPeriodEnd,
            'report_date' => $this->reportDate->format('Y-m-d'),
            'manufacturer' => $this->manufacturer,
            'report_type' => $this->reportType->value,
        ];

        if ($this->dataSourceDescriptions !== []) {
            $data['data_source_descriptions'] = $this->dataSourceDescriptions;
        }

        if ($this->totalUnitsDistributed !== null) {
            $data['total_units_distributed'] = $this->totalUnitsDistributed;
        }

        if ($this->totalComplaintsReceived !== null) {
            $data['total_complaints_received'] = $this->totalComplaintsReceived;
        }

        if ($this->adverseEvents !== []) {
            $data['adverse_events'] = array_map(
                static fn(AdverseEventSummary $e): array => $e->toArray(),
                $this->adverseEvents,
            );
        }

        if ($this->trendAnalysisSummary !== null) {
            $data['trend_analysis_summary'] = $this->trendAnalysisSummary;
        }

        if ($this->correctiveActions !== []) {
            $data['corrective_actions'] = $this->correctiveActions;
        }

        if ($this->conclusion !== null) {
            $data['conclusion'] = $this->conclusion;
        }

        return $data;
    }
}
