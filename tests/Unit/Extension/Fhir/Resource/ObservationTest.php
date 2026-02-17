<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Observation;
use Pulsar\Extension\Fhir\Resource\Quantity;
use Pulsar\Extension\Fhir\Resource\Reference;
use Pulsar\Extension\Fhir\Resource\ResourceType;

#[CoversClass(Observation::class)]
final class ObservationTest extends TestCase
{
    public function testResourceTypeIsObservation(): void
    {
        $obs = new Observation();

        self::assertSame(ResourceType::Observation, $obs->resourceType);
    }

    public function testVitalSignObservation(): void
    {
        $obs = new Observation(
            id: 'obs-bp-001',
            status: 'final',
            category: [
                new CodeableConcept(coding: [
                    new Coding(
                        system: 'http://terminology.hl7.org/CodeSystem/observation-category',
                        code: 'vital-signs',
                        display: 'Vital Signs',
                    ),
                ]),
            ],
            code: new CodeableConcept(coding: [
                new Coding(
                    system: 'http://loinc.org',
                    code: '85354-9',
                    display: 'Blood pressure panel',
                ),
            ]),
            subject: new Reference(reference: 'Patient/pt-001'),
            effectiveDateTime: '2024-06-15T10:30:00Z',
            valueQuantity: new Quantity(
                value: 120.0,
                unit: 'mmHg',
                system: 'http://unitsofmeasure.org',
                code: 'mm[Hg]',
            ),
        );

        $array = $obs->toArray();

        self::assertSame('Observation', $array['resourceType']);
        self::assertSame('final', $array['status']);

        $category = $array['category'];
        self::assertIsArray($category);
        $cat0 = $category[0];
        self::assertIsArray($cat0);
        $catCoding = $cat0['coding'];
        self::assertIsArray($catCoding);
        $catCoding0 = $catCoding[0];
        self::assertIsArray($catCoding0);
        self::assertSame('vital-signs', $catCoding0['code']);

        $code = $array['code'];
        self::assertIsArray($code);
        $codeCoding = $code['coding'];
        self::assertIsArray($codeCoding);
        $codeCoding0 = $codeCoding[0];
        self::assertIsArray($codeCoding0);
        self::assertSame('85354-9', $codeCoding0['code']);

        $vq = $array['valueQuantity'];
        self::assertIsArray($vq);
        self::assertSame(120.0, $vq['value']);
        self::assertSame('mmHg', $vq['unit']);
    }

    public function testFromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'Observation',
            'id' => 'obs-lab-001',
            'status' => 'final',
            'code' => [
                'coding' => [
                    ['system' => 'http://loinc.org', 'code' => '2160-0', 'display' => 'Creatinine'],
                ],
            ],
            'subject' => ['reference' => 'Patient/pt-001'],
            'valueQuantity' => ['value' => 1.2, 'unit' => 'mg/dL'],
            'effectiveDateTime' => '2024-06-15',
        ];

        $obs = Observation::fromArray($data);

        self::assertSame('obs-lab-001', $obs->id);
        self::assertSame('final', $obs->status);
        self::assertNotNull($obs->code);
        self::assertSame('2160-0', $obs->code->coding[0]->code);
        self::assertNotNull($obs->valueQuantity);
        self::assertSame(1.2, $obs->valueQuantity->value);
        self::assertSame('mg/dL', $obs->valueQuantity->unit);
    }

    public function testDataAbsentReason(): void
    {
        $obs = new Observation(
            id: 'obs-no-value',
            status: 'final',
            dataAbsentReason: new CodeableConcept(coding: [
                new Coding(code: 'not-performed', display: 'Not Performed'),
            ]),
        );

        $array = $obs->toArray();
        $dar = $array['dataAbsentReason'];
        self::assertIsArray($dar);
        $darCoding = $dar['coding'];
        self::assertIsArray($darCoding);
        $darCoding0 = $darCoding[0];
        self::assertIsArray($darCoding0);
        self::assertSame('not-performed', $darCoding0['code']);
        self::assertArrayNotHasKey('valueQuantity', $array);
    }

    public function testValueString(): void
    {
        $obs = new Observation(
            id: 'obs-text',
            status: 'final',
            valueString: 'Patient reports mild discomfort',
        );

        $array = $obs->toArray();
        self::assertSame('Patient reports mild discomfort', $array['valueString']);
    }
}
