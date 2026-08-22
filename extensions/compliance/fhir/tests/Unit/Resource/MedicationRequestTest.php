<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\MedicationRequest;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(MedicationRequest::class)]
final class MedicationRequestTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $rx = new MedicationRequest();

        $array = $rx->toArray();

        self::assertSame('MedicationRequest', $array['resourceType']);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('intent', $array);
        self::assertArrayNotHasKey('medicationCodeableConcept', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $rx = new MedicationRequest(
            id: 'rx-1',
            status: 'active',
            statusReason: new CodeableConcept(coding: [new Coding(code: 'clarification')]),
            intent: 'order',
            medicationCodeableConcept: new CodeableConcept(
                coding: [new Coding(system: 'http://www.nlm.nih.gov/research/umls/rxnorm', code: '1049502', display: 'Acetaminophen 325 MG')],
            ),
            medicationReference: new Reference(reference: 'Medication/med-1'),
            subject: new Reference(reference: 'Patient/123'),
            encounter: new Reference(reference: 'Encounter/456'),
            authoredOn: '2026-03-15',
            requester: new Reference(reference: 'Practitioner/789'),
            reasonCode: [new CodeableConcept(coding: [new Coding(code: '25064002')])],
            reasonReference: [new Reference(reference: 'Condition/headache-1')],
            priority: 'routine',
        );

        $array = $rx->toArray();

        self::assertSame('rx-1', $array['id']);
        self::assertSame('active', $array['status']);
        self::assertSame('clarification', $array['statusReason']['coding'][0]['code']);
        self::assertSame('order', $array['intent']);
        self::assertSame('1049502', $array['medicationCodeableConcept']['coding'][0]['code']);
        self::assertSame('Medication/med-1', $array['medicationReference']['reference']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('Encounter/456', $array['encounter']['reference']);
        self::assertSame('2026-03-15', $array['authoredOn']);
        self::assertSame('Practitioner/789', $array['requester']['reference']);
        self::assertCount(1, $array['reasonCode']);
        self::assertCount(1, $array['reasonReference']);
        self::assertSame('routine', $array['priority']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'id' => 'rx-42',
            'status' => 'completed',
            'intent' => 'order',
            'medicationCodeableConcept' => ['coding' => [['code' => '197696']]],
            'subject' => ['reference' => 'Patient/1'],
            'authoredOn' => '2026-01-01',
            'requester' => ['reference' => 'Practitioner/99'],
            'priority' => 'urgent',
        ];

        $rx = MedicationRequest::fromArray($data);

        self::assertSame('rx-42', $rx->id);
        self::assertSame('completed', $rx->status);
        self::assertSame('order', $rx->intent);
        self::assertNotNull($rx->medicationCodeableConcept);
        self::assertSame('197696', $rx->medicationCodeableConcept->coding[0]->code);
        self::assertSame('Patient/1', $rx->subject?->reference);
        self::assertSame('2026-01-01', $rx->authoredOn);
        self::assertSame('urgent', $rx->priority);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $rx = MedicationRequest::fromArray([]);

        self::assertNull($rx->id);
        self::assertNull($rx->status);
        self::assertNull($rx->intent);
        self::assertNull($rx->medicationCodeableConcept);
        self::assertNull($rx->medicationReference);
        self::assertNull($rx->subject);
        self::assertNull($rx->authoredOn);
        self::assertNull($rx->priority);
        self::assertSame([], $rx->identifier);
        self::assertSame([], $rx->reasonCode);
        self::assertSame([], $rx->reasonReference);
    }

    #[Test]
    public function fromArrayRejectsNonStringScalars(): void
    {
        $rx = MedicationRequest::fromArray([
            'id' => 123,
            'status' => true,
            'intent' => 42,
            'authoredOn' => ['not-a-string'],
            'priority' => false,
        ]);

        self::assertNull($rx->id);
        self::assertNull($rx->status);
        self::assertNull($rx->intent);
        self::assertNull($rx->authoredOn);
        self::assertNull($rx->priority);
    }
}
