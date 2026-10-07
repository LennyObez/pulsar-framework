<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Resource\ResourceType;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\SearchParameter;

#[CoversClass(CapabilityStatementBuilder::class)]
#[CoversClass(SearchParameter::class)]
final class CapabilityStatementBuilderTest extends TestCase
{
    #[Test]
    public function buildProducesValidCapabilityStatement(): void
    {
        $builder = new CapabilityStatementBuilder();
        $result = $builder->build();

        self::assertSame('CapabilityStatement', $result['resourceType']);
        self::assertSame('active', $result['status']);
        self::assertSame('instance', $result['kind']);
        self::assertSame('Pulsar FHIR Server', $result['publisher']);
        self::assertSame('Pulsar FHIR Server', $result['software']['name']);
        self::assertSame('1.0.0', $result['software']['version']);
        self::assertSame('4.0.1', $result['fhirVersion']);
        self::assertSame(['json'], $result['format']);
        self::assertSame('server', $result['rest'][0]['mode']);
        self::assertSame([], $result['rest'][0]['resource']);
    }

    #[Test]
    public function buildWithCustomServerName(): void
    {
        $builder = new CapabilityStatementBuilder(serverName: 'Hospital FHIR API');
        $result = $builder->build();

        self::assertSame('Hospital FHIR API', $result['publisher']);
        self::assertSame('Hospital FHIR API', $result['software']['name']);
    }

    #[Test]
    public function buildWithR5Version(): void
    {
        $builder = new CapabilityStatementBuilder(fhirVersion: FhirVersion::R5);
        $result = $builder->build();

        self::assertSame('5.0.0', $result['fhirVersion']);
    }

    #[Test]
    public function addResourceRegistersInteractionsAndSearchParams(): void
    {
        $builder = new CapabilityStatementBuilder();
        $builder->addResource(
            type: ResourceType::Patient,
            interactions: ['read', 'search-type', 'create'],
            searchParams: [
                new SearchParameter(
                    name: 'family',
                    type: 'string',
                    description: 'A portion of the family name',
                    expression: 'Patient.name.family',
                ),
            ],
        );

        $result = $builder->build();
        $resources = $result['rest'][0]['resource'];

        self::assertCount(1, $resources);
        self::assertSame('Patient', $resources[0]['type']);
        self::assertCount(3, $resources[0]['interaction']);
        self::assertSame('read', $resources[0]['interaction'][0]['code']);
        self::assertSame('search-type', $resources[0]['interaction'][1]['code']);
        self::assertSame('create', $resources[0]['interaction'][2]['code']);
        self::assertCount(1, $resources[0]['searchParam']);
        self::assertSame('family', $resources[0]['searchParam'][0]['name']);
        self::assertSame('string', $resources[0]['searchParam'][0]['type']);
        self::assertSame('Patient.name.family', $resources[0]['searchParam'][0]['expression']);
    }

    #[Test]
    public function addMultipleResources(): void
    {
        $builder = new CapabilityStatementBuilder();
        $builder->addResource(ResourceType::Patient, ['read']);
        $builder->addResource(ResourceType::Observation, ['read', 'search-type']);
        $builder->addResource(ResourceType::Encounter, ['read', 'create', 'update']);

        $result = $builder->build();
        $resources = $result['rest'][0]['resource'];

        self::assertCount(3, $resources);
        self::assertSame('Patient', $resources[0]['type']);
        self::assertSame('Observation', $resources[1]['type']);
        self::assertSame('Encounter', $resources[2]['type']);
    }

    #[Test]
    public function searchParameterToArrayWithoutExpression(): void
    {
        $param = new SearchParameter(
            name: '_id',
            type: 'token',
            description: 'Resource identifier',
        );

        $array = $param->toArray();

        self::assertSame('_id', $array['name']);
        self::assertSame('token', $array['type']);
        self::assertSame('Resource identifier', $array['description']);
        self::assertArrayNotHasKey('expression', $array);
    }

    #[Test]
    public function searchParameterToArrayWithExpression(): void
    {
        $param = new SearchParameter(
            name: 'status',
            type: 'token',
            description: 'The status of the observation',
            expression: 'Observation.status',
        );

        $array = $param->toArray();

        self::assertSame('Observation.status', $array['expression']);
    }

    #[Test]
    public function buildDateIsToday(): void
    {
        $builder = new CapabilityStatementBuilder();
        $result = $builder->build();

        self::assertSame(date('Y-m-d'), $result['date']);
    }
}
