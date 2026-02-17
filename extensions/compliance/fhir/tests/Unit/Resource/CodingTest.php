<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Resource\ResourceType;

use function count;

#[CoversClass(Coding::class)]
#[CoversClass(CodeableConcept::class)]
#[CoversClass(FhirVersion::class)]
#[CoversClass(ResourceType::class)]
final class CodingTest extends TestCase
{
    #[Test]
    public function minimalCodingHasAllNulls(): void
    {
        $coding = new Coding();

        self::assertNull($coding->system);
        self::assertNull($coding->version);
        self::assertNull($coding->code);
        self::assertNull($coding->display);
        self::assertNull($coding->userSelected);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $coding = new Coding();
        self::assertSame([], $coding->toArray());
    }

    #[Test]
    public function toArrayIncludesSetFields(): void
    {
        $coding = new Coding(
            system: 'http://snomed.info/sct',
            code: '73211009',
            display: 'Diabetes mellitus',
            userSelected: true,
        );

        $array = $coding->toArray();

        self::assertSame('http://snomed.info/sct', $array['system']);
        self::assertSame('73211009', $array['code']);
        self::assertSame('Diabetes mellitus', $array['display']);
        self::assertTrue($array['userSelected']);
        self::assertArrayNotHasKey('version', $array);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $data = [
            'system' => 'http://loinc.org',
            'code' => '85354-9',
            'display' => 'Blood pressure',
            'version' => '2.74',
        ];

        $coding = Coding::fromArray($data);

        self::assertSame('http://loinc.org', $coding->system);
        self::assertSame('85354-9', $coding->code);
        self::assertSame('Blood pressure', $coding->display);
        self::assertSame('2.74', $coding->version);
        self::assertNull($coding->userSelected);
    }

    #[Test]
    public function fromArrayIgnoresNonStringFields(): void
    {
        $coding = Coding::fromArray([
            'system' => 42,
            'code' => false,
            'display' => ['array'],
        ]);

        self::assertNull($coding->system);
        self::assertNull($coding->code);
        self::assertNull($coding->display);
    }

    #[Test]
    public function codeableConceptWithCodingAndText(): void
    {
        $concept = new CodeableConcept(
            coding: [
                new Coding(system: 'http://snomed.info/sct', code: '195967001', display: 'Asthma'),
            ],
            text: 'Asthma',
        );

        $array = $concept->toArray();

        self::assertCount(1, $array['coding']);
        self::assertSame('195967001', $array['coding'][0]['code']);
        self::assertSame('Asthma', $array['text']);
    }

    #[Test]
    public function codeableConceptFromArrayRoundTrips(): void
    {
        $data = [
            'coding' => [
                ['system' => 'http://loinc.org', 'code' => '8480-6'],
            ],
            'text' => 'Systolic blood pressure',
        ];

        $concept = CodeableConcept::fromArray($data);

        self::assertCount(1, $concept->coding);
        self::assertSame('8480-6', $concept->coding[0]->code);
        self::assertSame('Systolic blood pressure', $concept->text);
    }

    #[Test]
    public function emptyCodeableConceptToArrayIsEmpty(): void
    {
        $concept = new CodeableConcept();
        self::assertSame([], $concept->toArray());
    }

    /**
     * @return iterable<string, array{FhirVersion, string}>
     */
    public static function fhirVersionProvider(): iterable
    {
        yield 'R4' => [FhirVersion::R4, '4.0.1'];
        yield 'R5' => [FhirVersion::R5, '5.0.0'];
    }

    #[Test]
    #[DataProvider('fhirVersionProvider')]
    public function fhirVersionEnumValues(FhirVersion $version, string $expected): void
    {
        self::assertSame($expected, $version->value);
    }

    #[Test]
    public function resourceTypeEnumCoversAllSupportedTypes(): void
    {
        $cases = ResourceType::cases();
        self::assertGreaterThanOrEqual(12, count($cases));
        self::assertSame('Patient', ResourceType::Patient->value);
        self::assertSame('Observation', ResourceType::Observation->value);
        self::assertSame('Bundle', ResourceType::Bundle->value);
        self::assertSame('OperationOutcome', ResourceType::OperationOutcome->value);
    }
}
