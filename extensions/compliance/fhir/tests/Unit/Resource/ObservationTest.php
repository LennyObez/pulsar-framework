<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Observation;
use Pulsar\Extension\Fhir\Resource\Quantity;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(Observation::class)]
#[CoversClass(Quantity::class)]
#[CoversClass(Reference::class)]
final class ObservationTest extends TestCase
{
    #[Test]
    public function minimalObservationToArray(): void
    {
        $obs = new Observation();

        $array = $obs->toArray();

        self::assertSame('Observation', $array['resourceType']);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('code', $array);
        self::assertArrayNotHasKey('subject', $array);
    }

    #[Test]
    public function bloodPressureObservationToArray(): void
    {
        $obs = new Observation(
            id: 'bp-1',
            status: 'final',
            code: new CodeableConcept(
                coding: [new Coding(system: 'http://loinc.org', code: '85354-9', display: 'Blood pressure')],
                text: 'Blood pressure',
            ),
            subject: new Reference(reference: 'Patient/123', display: 'Jane Smith'),
            effectiveDateTime: '2026-03-15T09:00:00Z',
            valueQuantity: new Quantity(value: 120.0, unit: 'mmHg', system: 'http://unitsofmeasure.org', code: 'mm[Hg]'),
        );

        $array = $obs->toArray();

        self::assertSame('bp-1', $array['id']);
        self::assertSame('final', $array['status']);
        self::assertSame('85354-9', $array['code']['coding'][0]['code']);
        self::assertSame('Blood pressure', $array['code']['text']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('Jane Smith', $array['subject']['display']);
        self::assertSame('2026-03-15T09:00:00Z', $array['effectiveDateTime']);
        self::assertSame(120.0, $array['valueQuantity']['value']);
        self::assertSame('mmHg', $array['valueQuantity']['unit']);
    }

    #[Test]
    public function observationWithValueString(): void
    {
        $obs = new Observation(
            status: 'final',
            valueString: 'Positive',
        );

        $array = $obs->toArray();

        self::assertSame('Positive', $array['valueString']);
        self::assertArrayNotHasKey('valueQuantity', $array);
    }

    #[Test]
    public function observationWithDataAbsentReason(): void
    {
        $obs = new Observation(
            status: 'final',
            dataAbsentReason: new CodeableConcept(
                coding: [new Coding(system: 'http://terminology.hl7.org/CodeSystem/data-absent-reason', code: 'not-performed')],
            ),
        );

        $array = $obs->toArray();

        self::assertSame('not-performed', $array['dataAbsentReason']['coding'][0]['code']);
        self::assertArrayNotHasKey('valueQuantity', $array);
        self::assertArrayNotHasKey('valueString', $array);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'Observation',
            'id' => 'obs-42',
            'status' => 'final',
            'code' => [
                'coding' => [['system' => 'http://loinc.org', 'code' => '8867-4']],
            ],
            'subject' => ['reference' => 'Patient/1'],
            'effectiveDateTime' => '2026-01-01T00:00:00Z',
            'valueQuantity' => [
                'value' => 72,
                'unit' => '/min',
                'system' => 'http://unitsofmeasure.org',
            ],
        ];

        $obs = Observation::fromArray($data);

        self::assertSame('obs-42', $obs->id);
        self::assertSame('final', $obs->status);
        self::assertNotNull($obs->code);
        self::assertSame('8867-4', $obs->code->coding[0]->code);
        self::assertNotNull($obs->subject);
        self::assertSame('Patient/1', $obs->subject->reference);
        self::assertNotNull($obs->valueQuantity);
        self::assertSame('/min', $obs->valueQuantity->unit);
    }

    #[Test]
    public function fromArrayEmpty(): void
    {
        $obs = Observation::fromArray([]);

        self::assertNull($obs->id);
        self::assertNull($obs->status);
        self::assertNull($obs->code);
        self::assertNull($obs->subject);
        self::assertNull($obs->valueQuantity);
        self::assertSame([], $obs->identifier);
        self::assertSame([], $obs->category);
        self::assertSame([], $obs->performer);
    }

    #[Test]
    public function quantityToArrayMinimal(): void
    {
        $qty = new Quantity();

        self::assertSame([], $qty->toArray());
    }

    #[Test]
    public function quantityToArrayFull(): void
    {
        $qty = new Quantity(
            value: 98.6,
            comparator: '>',
            unit: 'degF',
            system: 'http://unitsofmeasure.org',
            code: '[degF]',
        );

        $array = $qty->toArray();

        self::assertSame(98.6, $array['value']);
        self::assertSame('>', $array['comparator']);
        self::assertSame('degF', $array['unit']);
        self::assertSame('http://unitsofmeasure.org', $array['system']);
        self::assertSame('[degF]', $array['code']);
    }

    #[Test]
    public function referenceToArrayMinimal(): void
    {
        $ref = new Reference();

        self::assertSame([], $ref->toArray());
    }

    #[Test]
    public function referenceToArrayFull(): void
    {
        $ref = new Reference(
            reference: 'Patient/456',
            type: 'Patient',
            display: 'John Doe',
        );

        $array = $ref->toArray();

        self::assertSame('Patient/456', $array['reference']);
        self::assertSame('Patient', $array['type']);
        self::assertSame('John Doe', $array['display']);
    }
}
