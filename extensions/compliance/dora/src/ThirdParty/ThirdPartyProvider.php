<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\ThirdParty;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * ICT third-party service provider record per DORA Articles 28-44.
 *
 * Financial entities must maintain a register of all contractual arrangements
 * on the use of ICT services provided by third-party providers.
 */
#[Api(since: '1.0.0')]
final readonly class ThirdPartyProvider
{
    /**
     * @param list<string> $servicesProvided  ICT services delivered by this provider
     * @param list<string> $subcontractors    Known subcontractors in the chain
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $jurisdiction,
        public ThirdPartyRiskLevel $riskLevel,
        public bool $supportsCriticalFunctions,
        public array $servicesProvided = [],
        public ?DateTimeImmutable $contractStartDate = null,
        public ?DateTimeImmutable $contractEndDate = null,
        public ?DateTimeImmutable $lastAuditDate = null,
        public ?string $exitStrategy = null,
        public array $subcontractors = [],
        public ?string $dataProcessingLocation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'jurisdiction' => $this->jurisdiction,
            'risk_level' => $this->riskLevel->value,
            'supports_critical_functions' => $this->supportsCriticalFunctions,
        ];

        if ($this->servicesProvided !== []) {
            $data['services_provided'] = $this->servicesProvided;
        }

        if ($this->contractStartDate !== null) {
            $data['contract_start_date'] = $this->contractStartDate->format('Y-m-d');
        }

        if ($this->contractEndDate !== null) {
            $data['contract_end_date'] = $this->contractEndDate->format('Y-m-d');
        }

        if ($this->lastAuditDate !== null) {
            $data['last_audit_date'] = $this->lastAuditDate->format('Y-m-d');
        }

        if ($this->exitStrategy !== null) {
            $data['exit_strategy'] = $this->exitStrategy;
        }

        if ($this->subcontractors !== []) {
            $data['subcontractors'] = $this->subcontractors;
        }

        if ($this->dataProcessingLocation !== null) {
            $data['data_processing_location'] = $this->dataProcessingLocation;
        }

        return $data;
    }
}
