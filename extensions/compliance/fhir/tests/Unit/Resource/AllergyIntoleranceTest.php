<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\AllergyIntolerance;
use Pulsar\Extension\Fhir\Resource\AllergyReaction;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Identifier;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(AllergyIntolerance::class)]
#[CoversClass(AllergyReaction::class)]
#[CoversClass(CodeableConcept::class)]
#[CoversClass(Identifier::class)]
final class AllergyIntoleranceTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $allergy = new AllergyIntolerance();

        $array = $allergy->toArray();

        self::assertSame('AllergyIntolerance', $array['resourceType']);
        self::assertArrayNotHasKey('clinicalStatus', $array);
        self::assertArrayNotHasKey('code', $array);
        self::assertArrayNotHasKey('patient', $array);
        self::assertArrayNotHasKey('reaction', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $allergy = new AllergyIntolerance(
            id: 'allergy-1',
            identifier: [new Identifier(system: 'http://hospital.example.com', value: 'A-001')],
            clinicalStatus: new CodeableConcept(
                coding: [new Coding(system: 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', code: 'active')],
            ),
            verificationStatus: new CodeableConcept(
                coding: [new Coding(code: 'confirmed')],
            ),
            type: 'allergy',
            category: [new CodeableConcept(coding: [new Coding(code: 'medication')])],
            criticality: 'high',
            code: new CodeableConcept(
                coding: [new Coding(system: 'http://snomed.info/sct', code: '387207008', display: 'Ibuprofen')],
                text: 'Ibuprofen',
            ),
            patient: new Reference(reference: 'Patient/123'),
            encounter: new Reference(reference: 'Encounter/456'),
            onsetDateTime: '2025-06-15',
            recordedDate: '2025-06-16',
            recorder: new Reference(reference: 'Practitioner/789'),
            asserter: new Reference(reference: 'Patient/123'),
            reaction: [
                new AllergyReaction(
                    substance: new CodeableConcept(coding: [new Coding(code: '387207008')]),
                    manifestation: [new CodeableConcept(coding: [new Coding(code: '271807003')])],
                    severity: 'severe',
                    onset: '2025-06-15T10:00:00Z',
                    description: 'Anaphylactic reaction',
                ),
            ],
        );

        $array = $allergy->toArray();

        self::assertSame('allergy-1', $array['id']);
        self::assertSame('http://hospital.example.com', $array['identifier'][0]['system']);
        self::assertSame('active', $array['clinicalStatus']['coding'][0]['code']);
        self::assertSame('confirmed', $array['verificationStatus']['coding'][0]['code']);
        self::assertSame('allergy', $array['type']);
        self::assertSame('medication', $array['category'][0]['coding'][0]['code']);
        self::assertSame('high', $array['criticality']);
        self::assertSame('Ibuprofen', $array['code']['text']);
        self::assertSame('Patient/123', $array['patient']['reference']);
        self::assertSame('Encounter/456', $array['encounter']['reference']);
        self::assertSame('2025-06-15', $array['onsetDateTime']);
        self::assertSame('2025-06-16', $array['recordedDate']);
        self::assertSame('Practitioner/789', $array['recorder']['reference']);
        self::assertSame('Patient/123', $array['asserter']['reference']);
        self::assertCount(1, $array['reaction']);
        self::assertSame('severe', $array['reaction'][0]['severity']);
        self::assertSame('Anaphylactic reaction', $array['reaction'][0]['description']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'resourceType' => 'AllergyIntolerance',
            'id' => 'ai-42',
            'type' => 'intolerance',
            'criticality' => 'low',
            'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '111088007']]],
            'patient' => ['reference' => 'Patient/99'],
            'onsetDateTime' => '2020-01-01',
            'recordedDate' => '2020-01-02',
            'reaction' => [
                [
                    'manifestation' => [['coding' => [['code' => '39579001']]]],
                    'severity' => 'mild',
                ],
            ],
        ];

        $allergy = AllergyIntolerance::fromArray($data);

        self::assertSame('ai-42', $allergy->id);
        self::assertSame('intolerance', $allergy->type);
        self::assertSame('low', $allergy->criticality);
        self::assertNotNull($allergy->code);
        self::assertSame('111088007', $allergy->code->coding[0]->code);
        self::assertNotNull($allergy->patient);
        self::assertSame('Patient/99', $allergy->patient->reference);
        self::assertCount(1, $allergy->reaction);
        self::assertSame('mild', $allergy->reaction[0]->severity);
        self::assertCount(1, $allergy->reaction[0]->manifestation);
    }

    #[Test]
    public function fromArrayEmptyReturnsNulls(): void
    {
        $allergy = AllergyIntolerance::fromArray([]);

        self::assertNull($allergy->id);
        self::assertNull($allergy->clinicalStatus);
        self::assertNull($allergy->verificationStatus);
        self::assertNull($allergy->type);
        self::assertNull($allergy->criticality);
        self::assertNull($allergy->code);
        self::assertNull($allergy->patient);
        self::assertSame([], $allergy->identifier);
        self::assertSame([], $allergy->category);
        self::assertSame([], $allergy->reaction);
    }

    #[Test]
    public function allergyReactionToArrayMinimal(): void
    {
        $reaction = new AllergyReaction();

        self::assertSame([], $reaction->toArray());
    }

    #[Test]
    public function allergyReactionFromArrayHandlesEmptyInput(): void
    {
        $reaction = AllergyReaction::fromArray([]);

        self::assertNull($reaction->substance);
        self::assertSame([], $reaction->manifestation);
        self::assertNull($reaction->severity);
        self::assertNull($reaction->onset);
        self::assertNull($reaction->description);
    }
}
