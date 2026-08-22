<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\ContactPoint;
use Pulsar\Extension\Fhir\Resource\HumanName;
use Pulsar\Extension\Fhir\Resource\Identifier;
use Pulsar\Extension\Fhir\Resource\Meta;
use Pulsar\Extension\Fhir\Resource\Patient;
use Pulsar\Extension\Fhir\Resource\Reference;
use Pulsar\Extension\Fhir\Resource\ResourceType;

#[CoversClass(Patient::class)]
final class PatientTest extends TestCase
{
    #[Test]
    public function minimalPatientHasCorrectResourceType(): void
    {
        $patient = new Patient();

        self::assertSame(ResourceType::Patient, $patient->resourceType);
        self::assertNull($patient->id);
        self::assertNull($patient->gender);
        self::assertNull($patient->birthDate);
        self::assertSame([], $patient->identifier);
        self::assertSame([], $patient->name);
        self::assertSame([], $patient->telecom);
    }

    #[Test]
    public function toArrayWithMinimalPatient(): void
    {
        $patient = new Patient();
        $array = $patient->toArray();

        self::assertSame('Patient', $array['resourceType']);
        self::assertArrayNotHasKey('id', $array);
        self::assertArrayNotHasKey('gender', $array);
        self::assertArrayNotHasKey('name', $array);
    }

    #[Test]
    public function toArrayWithFullPatient(): void
    {
        $patient = new Patient(
            id: 'pat-123',
            meta: new Meta(versionId: '1'),
            language: 'en',
            identifier: [new Identifier(system: 'urn:oid:2.16.840', value: 'MRN-456')],
            active: true,
            name: [new HumanName(family: 'Doe', given: ['Jane'])],
            telecom: [new ContactPoint(system: 'phone', value: '+1-555-0100')],
            gender: 'female',
            birthDate: '1990-03-15',
            managingOrganization: new Reference(reference: 'Organization/org-1'),
        );

        $array = $patient->toArray();

        self::assertSame('Patient', $array['resourceType']);
        self::assertSame('pat-123', $array['id']);
        self::assertTrue($array['active']);
        self::assertSame('female', $array['gender']);
        self::assertSame('1990-03-15', $array['birthDate']);
        self::assertCount(1, $array['identifier']);
        self::assertCount(1, $array['name']);
        self::assertCount(1, $array['telecom']);
        self::assertArrayHasKey('managingOrganization', $array);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $data = [
            'id' => 'pat-456',
            'language' => 'de',
            'active' => false,
            'gender' => 'male',
            'birthDate' => '1985-07-22',
            'name' => [['family' => 'Schmidt', 'given' => ['Hans']]],
            'identifier' => [['system' => 'http://hospital.example', 'value' => 'H-789']],
        ];

        $patient = Patient::fromArray($data);

        self::assertSame('pat-456', $patient->id);
        self::assertSame('de', $patient->language);
        self::assertFalse($patient->active);
        self::assertSame('male', $patient->gender);
        self::assertSame('1985-07-22', $patient->birthDate);
        self::assertCount(1, $patient->name);
        self::assertSame('Schmidt', $patient->name[0]->family);
        self::assertCount(1, $patient->identifier);
        self::assertSame('H-789', $patient->identifier[0]->value);
    }

    #[Test]
    public function fromArrayWithEmptyArrayCreatesMinimalPatient(): void
    {
        $patient = Patient::fromArray([]);

        self::assertNull($patient->id);
        self::assertNull($patient->gender);
        self::assertSame([], $patient->name);
        self::assertSame([], $patient->identifier);
    }

    #[Test]
    public function deceasedFieldsIncludedInToArray(): void
    {
        $patient = new Patient(
            deceasedBoolean: true,
            deceasedDateTime: '2025-12-01T10:00:00+00:00',
        );

        $array = $patient->toArray();

        self::assertTrue($array['deceasedBoolean']);
        self::assertSame('2025-12-01T10:00:00+00:00', $array['deceasedDateTime']);
    }
}
