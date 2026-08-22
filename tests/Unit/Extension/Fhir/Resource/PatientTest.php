<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\ContactPoint;
use Pulsar\Extension\Fhir\Resource\HumanName;
use Pulsar\Extension\Fhir\Resource\Identifier;
use Pulsar\Extension\Fhir\Resource\Patient;
use Pulsar\Extension\Fhir\Resource\Reference;
use Pulsar\Extension\Fhir\Resource\ResourceType;

#[CoversClass(Patient::class)]
final class PatientTest extends TestCase
{
    public function testResourceTypeIsPatient(): void
    {
        $patient = new Patient();

        self::assertSame(ResourceType::Patient, $patient->resourceType);
    }

    public function testMinimalPatientToArray(): void
    {
        $patient = new Patient(id: 'pt-001');

        $array = $patient->toArray();

        self::assertSame('Patient', $array['resourceType']);
        self::assertSame('pt-001', $array['id']);
        self::assertArrayNotHasKey('name', $array);
        self::assertArrayNotHasKey('gender', $array);
    }

    public function testFullPatientToArray(): void
    {
        $patient = new Patient(
            id: 'pt-123',
            identifier: [
                new Identifier(system: 'http://hospital.org/mrn', value: 'MRN001'),
            ],
            active: true,
            name: [
                new HumanName(use: 'official', family: 'Smith', given: ['John', 'Michael']),
            ],
            telecom: [
                new ContactPoint(system: 'phone', value: '+1-555-0100', use: 'home'),
            ],
            gender: 'male',
            birthDate: '1985-03-15',
        );

        $array = $patient->toArray();

        self::assertSame('Patient', $array['resourceType']);
        self::assertSame('pt-123', $array['id']);
        self::assertTrue($array['active']);
        self::assertSame('male', $array['gender']);
        self::assertSame('1985-03-15', $array['birthDate']);
        self::assertIsArray($array['name']);
        self::assertCount(1, $array['name']);
        $name0 = $array['name'][0];
        self::assertIsArray($name0);
        self::assertSame('Smith', $name0['family']);
        self::assertSame(['John', 'Michael'], $name0['given']);
        self::assertIsArray($array['telecom']);
        self::assertCount(1, $array['telecom']);
        $telecom0 = $array['telecom'][0];
        self::assertIsArray($telecom0);
        self::assertSame('+1-555-0100', $telecom0['value']);
    }

    public function testFromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'Patient',
            'id' => 'pt-456',
            'active' => true,
            'gender' => 'female',
            'birthDate' => '1990-07-20',
            'name' => [
                ['use' => 'official', 'family' => 'Doe', 'given' => ['Jane']],
            ],
            'identifier' => [
                ['system' => 'http://example.org', 'value' => 'ID-001'],
            ],
            'telecom' => [
                ['system' => 'email', 'value' => 'jane@example.com'],
            ],
        ];

        $patient = Patient::fromArray($data);

        self::assertSame('pt-456', $patient->id);
        self::assertTrue($patient->active);
        self::assertSame('female', $patient->gender);
        self::assertSame('1990-07-20', $patient->birthDate);
        self::assertCount(1, $patient->name);
        self::assertSame('Doe', $patient->name[0]->family);
        self::assertSame(['Jane'], $patient->name[0]->given);
    }

    public function testDeceasedFields(): void
    {
        $patient = new Patient(
            id: 'pt-deceased',
            deceasedBoolean: true,
        );

        $array = $patient->toArray();
        self::assertTrue($array['deceasedBoolean']);
        self::assertArrayNotHasKey('deceasedDateTime', $array);

        $patient2 = new Patient(
            id: 'pt-deceased-dt',
            deceasedDateTime: '2024-01-15T10:30:00Z',
        );

        $array2 = $patient2->toArray();
        self::assertSame('2024-01-15T10:30:00Z', $array2['deceasedDateTime']);
    }

    public function testManagingOrganization(): void
    {
        $patient = new Patient(
            id: 'pt-org',
            managingOrganization: new Reference(
                reference: 'Organization/org-001',
                display: 'General Hospital',
            ),
        );

        $array = $patient->toArray();
        $org = $array['managingOrganization'];
        self::assertIsArray($org);
        self::assertSame('Organization/org-001', $org['reference']);
        self::assertSame('General Hospital', $org['display']);
    }

    public function testFromArrayWithEmptyDataCreatesMinimalPatient(): void
    {
        $patient = Patient::fromArray([]);

        self::assertNull($patient->id);
        self::assertNull($patient->active);
        self::assertNull($patient->gender);
        self::assertSame([], $patient->name);
        self::assertSame([], $patient->identifier);
    }
}
