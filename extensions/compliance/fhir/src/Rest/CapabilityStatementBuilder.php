<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Rest;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Resource\ResourceType;

/**
 * Builds a FHIR CapabilityStatement that describes the server's FHIR capabilities.
 *
 * The CapabilityStatement is served at the /metadata endpoint and tells clients
 * what resource types, interactions, and search parameters the server supports.
 *
 * @see https://www.hl7.org/fhir/capabilitystatement.html
 * @api
 */
#[Api(since: '1.0.0')]
final class CapabilityStatementBuilder
{
    /** @var list<array{type: string, interaction: list<array{code: string}>, searchParam: list<array<string, string>>}> */
    private array $resources = [];

    public function __construct(
        private readonly string $serverName = 'Pulsar FHIR Server',
        private readonly FhirVersion $fhirVersion = FhirVersion::R4,
    ) {}

    /**
     * Register a supported resource type with its interactions and search parameters.
     *
     * @param list<string>          $interactions   Supported interactions (read, search-type, create, update, delete)
     * @param list<SearchParameter> $searchParams   Supported search parameters
     */
    public function addResource(
        ResourceType $type,
        array $interactions = [],
        array $searchParams = [],
    ): void {
        $this->resources[] = [
            'type' => $type->value,
            'interaction' => array_map(
                static fn(string $code): array => ['code' => $code],
                $interactions,
            ),
            'searchParam' => array_map(
                static fn(SearchParameter $p): array => $p->toArray(),
                $searchParams,
            ),
        ];
    }

    /**
     * Build the CapabilityStatement as a FHIR-conformant array.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => new DateTimeImmutable()->format('Y-m-d'),
            'publisher' => $this->serverName,
            'kind' => 'instance',
            'software' => [
                'name' => $this->serverName,
                'version' => '1.0.0',
            ],
            'fhirVersion' => $this->fhirVersion->value,
            'format' => ['json'],
            'rest' => [
                [
                    'mode' => 'server',
                    'resource' => $this->resources,
                ],
            ],
        ];
    }
}
