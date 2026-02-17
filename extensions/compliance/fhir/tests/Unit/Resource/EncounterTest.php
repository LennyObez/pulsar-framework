<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Encounter;
use Pulsar\Extension\Fhir\Resource\Period;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(Encounter::class)]
final class EncounterTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $encounter = new Encounter();

        $array = $encounter->toArray();

        self::assertSame('Encounter', $array['resourceType']);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('class', $array);
        self::assertArrayNotHasKey('subject', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $encounter = new Encounter(
            id: 'enc-1',
            status: 'in-progress',
            class_: new Coding(
                system: 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                code: 'IMP',
                display: 'inpatient encounter',
            ),
            type: [new CodeableConcept(coding: [new Coding(code: 'consultation')])],
            subject: new Reference(reference: 'Patient/123'),
            period: new Period(start: '2026-03-15T08:00:00Z', end: '2026-03-15T17:00:00Z'),
            reasonCode: [new CodeableConcept(coding: [new Coding(code: '38341003')])],
            reasonReference: [new Reference(reference: 'Condition/456')],
            serviceProvider: new Reference(reference: 'Organization/hosp-1'),
        );

        $array = $encounter->toArray();

        self::assertSame('enc-1', $array['id']);
        self::assertSame('in-progress', $array['status']);
        self::assertSame('IMP', $array['class']['code']);
        self::assertSame('inpatient encounter', $array['class']['display']);
        self::assertSame('consultation', $array['type'][0]['coding'][0]['code']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('2026-03-15T08:00:00Z', $array['period']['start']);
        self::assertSame('2026-03-15T17:00:00Z', $array['period']['end']);
        self::assertCount(1, $array['reasonCode']);
        self::assertCount(1, $array['reasonReference']);
        self::assertSame('Organization/hosp-1', $array['serviceProvider']['reference']);
    }

    #[Test]
    public function classPropertyMapsToClassKey(): void
    {
        $encounter = new Encounter(
            class_: new Coding(code: 'AMB'),
        );

        $array = $encounter->toArray();

        self::assertSame('AMB', $array['class']['code']);
        self::assertArrayNotHasKey('class_', $array);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'id' => 'enc-42',
            'status' => 'finished',
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB'],
            'type' => [['coding' => [['code' => 'follow-up']]]],
            'subject' => ['reference' => 'Patient/1'],
            'period' => ['start' => '2026-01-01T09:00:00Z', 'end' => '2026-01-01T10:00:00Z'],
            'reasonCode' => [['coding' => [['code' => '185345009']]]],
            'reasonReference' => [['reference' => 'Observation/1']],
            'serviceProvider' => ['reference' => 'Organization/clinic-1'],
        ];

        $encounter = Encounter::fromArray($data);

        self::assertSame('enc-42', $encounter->id);
        self::assertSame('finished', $encounter->status);
        self::assertNotNull($encounter->class_);
        self::assertSame('AMB', $encounter->class_->code);
        self::assertCount(1, $encounter->type);
        self::assertSame('Patient/1', $encounter->subject?->reference);
        self::assertNotNull($encounter->period);
        self::assertSame('2026-01-01T09:00:00Z', $encounter->period->start);
        self::assertCount(1, $encounter->reasonCode);
        self::assertCount(1, $encounter->reasonReference);
        self::assertSame('Organization/clinic-1', $encounter->serviceProvider?->reference);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $encounter = Encounter::fromArray([]);

        self::assertNull($encounter->id);
        self::assertNull($encounter->status);
        self::assertNull($encounter->class_);
        self::assertNull($encounter->subject);
        self::assertNull($encounter->period);
        self::assertNull($encounter->serviceProvider);
        self::assertSame([], $encounter->identifier);
        self::assertSame([], $encounter->type);
        self::assertSame([], $encounter->reasonCode);
        self::assertSame([], $encounter->reasonReference);
    }
}
