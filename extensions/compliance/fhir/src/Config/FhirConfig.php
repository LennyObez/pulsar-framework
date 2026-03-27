<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\FhirVersion;

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
     * @param array{
     *     server_name?: string,
     *     fhir_version?: string,
     *     base_path?: string,
     *     supported_resource_types?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            serverName: $data['server_name'] ?? 'Pulsar FHIR Server',
            fhirVersion: FhirVersion::tryFrom($data['fhir_version'] ?? '') ?? FhirVersion::R4,
            basePath: $data['base_path'] ?? '/fhir',
            supportedResourceTypes: $data['supported_resource_types'] ?? [
                'Patient',
                'Observation',
                'Encounter',
                'Condition',
                'MedicationRequest',
                'AllergyIntolerance',
                'Procedure',
                'DiagnosticReport',
            ],
        );
    }
}
