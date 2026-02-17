<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Condition;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(Condition::class)]
final class ConditionTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $condition = new Condition();

        $array = $condition->toArray();

        self::assertSame('Condition', $array['resourceType']);
        self::assertArrayNotHasKey('clinicalStatus', $array);
        self::assertArrayNotHasKey('code', $array);
        self::assertArrayNotHasKey('subject', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $condition = new Condition(
            id: 'cond-1',
            clinicalStatus: new CodeableConcept(
                coding: [new Coding(code: 'active')],
            ),
            verificationStatus: new CodeableConcept(
                coding: [new Coding(code: 'confirmed')],
            ),
            category: [new CodeableConcept(coding: [new Coding(code: 'encounter-diagnosis')])],
            severity: new CodeableConcept(coding: [new Coding(code: 'severe')]),
            code: new CodeableConcept(
                coding: [new Coding(system: 'http://snomed.info/sct', code: '38341003', display: 'Hypertension')],
                text: 'Hypertension',
            ),
            subject: new Reference(reference: 'Patient/123'),
            encounter: new Reference(reference: 'Encounter/456'),
            onsetDateTime: '2020-01-15',
            abatementDateTime: '2025-12-01',
            recordedDate: '2020-01-16',
            recorder: new Reference(reference: 'Practitioner/789'),
            asserter: new Reference(reference: 'Practitioner/789'),
        );

        $array = $condition->toArray();

        self::assertSame('cond-1', $array['id']);
        self::assertSame('active', $array['clinicalStatus']['coding'][0]['code']);
        self::assertSame('confirmed', $array['verificationStatus']['coding'][0]['code']);
        self::assertSame('encounter-diagnosis', $array['category'][0]['coding'][0]['code']);
        self::assertSame('severe', $array['severity']['coding'][0]['code']);
        self::assertSame('Hypertension', $array['code']['text']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('Encounter/456', $array['encounter']['reference']);
        self::assertSame('2020-01-15', $array['onsetDateTime']);
        self::assertSame('2025-12-01', $array['abatementDateTime']);
        self::assertSame('2020-01-16', $array['recordedDate']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'id' => 'cond-42',
            'clinicalStatus' => ['coding' => [['code' => 'resolved']]],
            'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '73211009']]],
            'subject' => ['reference' => 'Patient/1'],
            'onsetDateTime' => '2019-06-01',
            'abatementDateTime' => '2019-07-15',
        ];

        $condition = Condition::fromArray($data);

        self::assertSame('cond-42', $condition->id);
        self::assertNotNull($condition->clinicalStatus);
        self::assertSame('resolved', $condition->clinicalStatus->coding[0]->code);
        self::assertNotNull($condition->code);
        self::assertSame('73211009', $condition->code->coding[0]->code);
        self::assertSame('Patient/1', $condition->subject?->reference);
        self::assertSame('2019-06-01', $condition->onsetDateTime);
        self::assertSame('2019-07-15', $condition->abatementDateTime);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $condition = Condition::fromArray([]);

        self::assertNull($condition->id);
        self::assertNull($condition->clinicalStatus);
        self::assertNull($condition->severity);
        self::assertNull($condition->code);
        self::assertNull($condition->subject);
        self::assertNull($condition->onsetDateTime);
        self::assertNull($condition->abatementDateTime);
        self::assertSame([], $condition->identifier);
        self::assertSame([], $condition->category);
    }
}
