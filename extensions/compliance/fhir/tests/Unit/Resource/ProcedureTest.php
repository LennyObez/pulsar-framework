<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Period;
use Pulsar\Extension\Fhir\Resource\Procedure;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(Procedure::class)]
final class ProcedureTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $procedure = new Procedure();

        $array = $procedure->toArray();

        self::assertSame('Procedure', $array['resourceType']);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('code', $array);
        self::assertArrayNotHasKey('outcome', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $procedure = new Procedure(
            id: 'proc-1',
            status: 'completed',
            statusReason: new CodeableConcept(coding: [new Coding(code: 'medical-precaution')]),
            category: new CodeableConcept(coding: [new Coding(code: '103693007')]),
            code: new CodeableConcept(
                coding: [new Coding(system: 'http://snomed.info/sct', code: '80146002', display: 'Appendectomy')],
            ),
            subject: new Reference(reference: 'Patient/123'),
            encounter: new Reference(reference: 'Encounter/456'),
            performedDateTime: '2026-03-15T10:00:00Z',
            performedPeriod: new Period(start: '2026-03-15T10:00:00Z', end: '2026-03-15T11:30:00Z'),
            recorder: new Reference(reference: 'Practitioner/789'),
            asserter: new Reference(reference: 'Practitioner/789'),
            reasonCode: [new CodeableConcept(coding: [new Coding(code: '74400008')])],
            reasonReference: [new Reference(reference: 'Condition/appendicitis-1')],
            outcome: new CodeableConcept(coding: [new Coding(code: '385669000')]),
            report: [new Reference(reference: 'DiagnosticReport/path-1')],
        );

        $array = $procedure->toArray();

        self::assertSame('proc-1', $array['id']);
        self::assertSame('completed', $array['status']);
        self::assertSame('medical-precaution', $array['statusReason']['coding'][0]['code']);
        self::assertSame('103693007', $array['category']['coding'][0]['code']);
        self::assertSame('Appendectomy', $array['code']['coding'][0]['display']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('2026-03-15T10:00:00Z', $array['performedDateTime']);
        self::assertSame('2026-03-15T10:00:00Z', $array['performedPeriod']['start']);
        self::assertSame('Practitioner/789', $array['recorder']['reference']);
        self::assertCount(1, $array['reasonCode']);
        self::assertCount(1, $array['reasonReference']);
        self::assertSame('385669000', $array['outcome']['coding'][0]['code']);
        self::assertCount(1, $array['report']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'id' => 'proc-42',
            'status' => 'in-progress',
            'code' => ['coding' => [['code' => '274025005']]],
            'subject' => ['reference' => 'Patient/1'],
            'performedPeriod' => ['start' => '2026-01-01T14:00:00Z'],
            'outcome' => ['coding' => [['code' => 'successful']]],
            'report' => [['reference' => 'DiagnosticReport/1']],
        ];

        $procedure = Procedure::fromArray($data);

        self::assertSame('proc-42', $procedure->id);
        self::assertSame('in-progress', $procedure->status);
        self::assertNotNull($procedure->code);
        self::assertSame('274025005', $procedure->code->coding[0]->code);
        self::assertNotNull($procedure->performedPeriod);
        self::assertSame('2026-01-01T14:00:00Z', $procedure->performedPeriod->start);
        self::assertNull($procedure->performedPeriod->end);
        self::assertNotNull($procedure->outcome);
        self::assertCount(1, $procedure->report);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $procedure = Procedure::fromArray([]);

        self::assertNull($procedure->id);
        self::assertNull($procedure->status);
        self::assertNull($procedure->statusReason);
        self::assertNull($procedure->category);
        self::assertNull($procedure->code);
        self::assertNull($procedure->subject);
        self::assertNull($procedure->performedDateTime);
        self::assertNull($procedure->performedPeriod);
        self::assertNull($procedure->outcome);
        self::assertSame([], $procedure->identifier);
        self::assertSame([], $procedure->reasonCode);
        self::assertSame([], $procedure->report);
    }
}
