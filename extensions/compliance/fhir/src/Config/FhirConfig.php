<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Support\Coerce;

/**
 * Configuration for the FHIR extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FhirConfig
{
    /**
     * @param list<string> $supportedResourceTypes Resource types the server supports
     */
    public function __construct(
        public string $serverName = 'Pulsar FHIR Server',
        public FhirVersion $fhirVersion = FhirVersion::R4,
        public string $basePath = '/fhir',
        public array $supportedResourceTypes = [
            'Patient',
            'Observation',
            'Encounter',
            'Condition',
            'MedicationRequest',
            'AllergyIntolerance',
            'Procedure',
            'DiagnosticReport',
        ],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            serverName: Coerce::string($data['server_name'] ?? null, 'Pulsar FHIR Server'),
            fhirVersion: FhirVersion::tryFrom(Coerce::string($data['fhir_version'] ?? null)) ?? FhirVersion::R4,
            basePath: Coerce::string($data['base_path'] ?? null, '/fhir'),
            supportedResourceTypes: Coerce::listOfString($data['supported_resource_types'] ?? null, [
                'Patient',
                'Observation',
                'Encounter',
                'Condition',
                'MedicationRequest',
                'AllergyIntolerance',
                'Procedure',
                'DiagnosticReport',
            ]),
        );
    }
}
