<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Resource\ResourceType;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\SearchParameter;

#[CoversClass(CapabilityStatementBuilder::class)]
final class CapabilityStatementBuilderTest extends TestCase
{
    public function testBuildMinimalCapabilityStatement(): void
    {
        $builder = new CapabilityStatementBuilder('Test Server');
        $statement = $builder->build();

        self::assertSame('CapabilityStatement', $statement['resourceType']);
        self::assertSame('active', $statement['status']);
        self::assertSame('Test Server', $statement['publisher']);
        self::assertSame('instance', $statement['kind']);
        self::assertSame('4.0.1', $statement['fhirVersion']);
        self::assertSame(['json'], $statement['format']);
    }

    public function testBuildWithR5Version(): void
    {
        $builder = new CapabilityStatementBuilder('R5 Server', FhirVersion::R5);
        $statement = $builder->build();

        self::assertSame('5.0.0', $statement['fhirVersion']);
    }

    public function testAddResourceWithInteractions(): void
    {
        $builder = new CapabilityStatementBuilder();
        $builder->addResource(ResourceType::Patient, ['read', 'search-type', 'create']);

        $statement = $builder->build();
        $rest = $statement['rest'];
        self::assertIsArray($rest);
        $restFirst = $rest[0];
        self::assertIsArray($restFirst);
        $resources = $restFirst['resource'];
        self::assertIsArray($resources);

        self::assertCount(1, $resources);
        $resource0 = $resources[0];
        self::assertIsArray($resource0);
        self::assertSame('Patient', $resource0['type']);
        $interactions = $resource0['interaction'];
        self::assertIsArray($interactions);
        self::assertCount(3, $interactions);
        $interaction0 = $interactions[0];
        self::assertIsArray($interaction0);
        self::assertSame('read', $interaction0['code']);
    }

    public function testAddResourceWithSearchParams(): void
    {
        $builder = new CapabilityStatementBuilder();
        $builder->addResource(ResourceType::Patient, ['read'], [
            new SearchParameter('name', 'string', 'Patient name'),
            new SearchParameter('birthdate', 'date', 'Date of birth'),
        ]);

        $statement = $builder->build();
        $rest = $statement['rest'];
        self::assertIsArray($rest);
        $restFirst = $rest[0];
        self::assertIsArray($restFirst);
        $resources = $restFirst['resource'];
        self::assertIsArray($resources);
        $resource0 = $resources[0];
        self::assertIsArray($resource0);
        $searchParams = $resource0['searchParam'];
        self::assertIsArray($searchParams);

        self::assertCount(2, $searchParams);
        $param0 = $searchParams[0];
        self::assertIsArray($param0);
        self::assertSame('name', $param0['name']);
        self::assertSame('string', $param0['type']);
        $param1 = $searchParams[1];
        self::assertIsArray($param1);
        self::assertSame('birthdate', $param1['name']);
    }

    public function testMultipleResourceTypes(): void
    {
        $builder = new CapabilityStatementBuilder();
        $builder->addResource(ResourceType::Patient, ['read']);
        $builder->addResource(ResourceType::Observation, ['read', 'search-type']);
        $builder->addResource(ResourceType::Condition, ['read']);

        $statement = $builder->build();
        $rest = $statement['rest'];
        self::assertIsArray($rest);
        $restFirst = $rest[0];
        self::assertIsArray($restFirst);
        $resources = $restFirst['resource'];
        self::assertIsArray($resources);

        self::assertCount(3, $resources);
        $r0 = $resources[0];
        self::assertIsArray($r0);
        self::assertSame('Patient', $r0['type']);
        $r1 = $resources[1];
        self::assertIsArray($r1);
        self::assertSame('Observation', $r1['type']);
        $r2 = $resources[2];
        self::assertIsArray($r2);
        self::assertSame('Condition', $r2['type']);
    }
}
