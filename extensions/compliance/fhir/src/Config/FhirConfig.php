<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\FhirVersion;

use function is_string;

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
        /** @var list<string> $supportedTypes */
        $supportedTypes = $data['supported_resource_types'] ?? [
            'Patient',
            'Observation',
            'Encounter',
            'Condition',
            'MedicationRequest',
            'AllergyIntolerance',
            'Procedure',
            'DiagnosticReport',
        ];

        return new self(
            serverName: is_string($data['server_name'] ?? null) ? $data['server_name'] : 'Pulsar FHIR Server',
            fhirVersion: is_string($data['fhir_version'] ?? null)
                ? FhirVersion::from($data['fhir_version'])
                : FhirVersion::R4,
            basePath: is_string($data['base_path'] ?? null) ? $data['base_path'] : '/fhir',
            supportedResourceTypes: $supportedTypes,
        );
    }
}
