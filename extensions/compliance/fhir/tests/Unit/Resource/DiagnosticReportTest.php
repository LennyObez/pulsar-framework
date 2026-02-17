<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\DiagnosticReport;
use Pulsar\Extension\Fhir\Resource\Period;
use Pulsar\Extension\Fhir\Resource\Reference;

#[CoversClass(DiagnosticReport::class)]
#[CoversClass(Period::class)]
final class DiagnosticReportTest extends TestCase
{
    #[Test]
    public function minimalToArrayHasResourceType(): void
    {
        $report = new DiagnosticReport();

        $array = $report->toArray();

        self::assertSame('DiagnosticReport', $array['resourceType']);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('code', $array);
        self::assertArrayNotHasKey('conclusion', $array);
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $report = new DiagnosticReport(
            id: 'dr-1',
            status: 'final',
            category: [new CodeableConcept(coding: [new Coding(code: 'LAB')])],
            code: new CodeableConcept(
                coding: [new Coding(system: 'http://loinc.org', code: '58410-2', display: 'CBC')],
            ),
            subject: new Reference(reference: 'Patient/123'),
            encounter: new Reference(reference: 'Encounter/456'),
            effectiveDateTime: '2026-03-15T09:00:00Z',
            effectivePeriod: new Period(start: '2026-03-15T08:00:00Z', end: '2026-03-15T09:00:00Z'),
            issued: '2026-03-15T12:00:00Z',
            performer: [new Reference(reference: 'Organization/lab-1')],
            result: [
                new Reference(reference: 'Observation/wbc-1'),
                new Reference(reference: 'Observation/rbc-1'),
            ],
            conclusion: 'Normal complete blood count',
            conclusionCode: new CodeableConcept(coding: [new Coding(code: 'normal')]),
        );

        $array = $report->toArray();

        self::assertSame('dr-1', $array['id']);
        self::assertSame('final', $array['status']);
        self::assertSame('LAB', $array['category'][0]['coding'][0]['code']);
        self::assertSame('58410-2', $array['code']['coding'][0]['code']);
        self::assertSame('Patient/123', $array['subject']['reference']);
        self::assertSame('2026-03-15T09:00:00Z', $array['effectiveDateTime']);
        self::assertSame('2026-03-15T08:00:00Z', $array['effectivePeriod']['start']);
        self::assertSame('2026-03-15T09:00:00Z', $array['effectivePeriod']['end']);
        self::assertSame('2026-03-15T12:00:00Z', $array['issued']);
        self::assertCount(1, $array['performer']);
        self::assertCount(2, $array['result']);
        self::assertSame('Observation/wbc-1', $array['result'][0]['reference']);
        self::assertSame('Normal complete blood count', $array['conclusion']);
        self::assertSame('normal', $array['conclusionCode']['coding'][0]['code']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'id' => 'dr-42',
            'status' => 'preliminary',
            'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '24323-8']]],
            'subject' => ['reference' => 'Patient/1'],
            'effectivePeriod' => ['start' => '2026-01-01', 'end' => '2026-01-02'],
            'issued' => '2026-01-03T10:00:00Z',
            'performer' => [['reference' => 'Practitioner/99']],
            'result' => [['reference' => 'Observation/1']],
            'conclusion' => 'Preliminary findings',
        ];

        $report = DiagnosticReport::fromArray($data);

        self::assertSame('dr-42', $report->id);
        self::assertSame('preliminary', $report->status);
        self::assertNotNull($report->code);
        self::assertSame('24323-8', $report->code->coding[0]->code);
        self::assertNotNull($report->effectivePeriod);
        self::assertSame('2026-01-01', $report->effectivePeriod->start);
        self::assertSame('2026-01-02', $report->effectivePeriod->end);
        self::assertCount(1, $report->performer);
        self::assertCount(1, $report->result);
        self::assertSame('Preliminary findings', $report->conclusion);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $report = DiagnosticReport::fromArray([]);

        self::assertNull($report->id);
        self::assertNull($report->status);
        self::assertNull($report->code);
        self::assertNull($report->effectiveDateTime);
        self::assertNull($report->effectivePeriod);
        self::assertNull($report->conclusion);
        self::assertSame([], $report->identifier);
        self::assertSame([], $report->performer);
        self::assertSame([], $report->result);
    }

    #[Test]
    public function periodToArrayMinimal(): void
    {
        $period = new Period();

        self::assertSame([], $period->toArray());
    }

    #[Test]
    public function periodToArrayFull(): void
    {
        $period = new Period(start: '2026-01-01', end: '2026-12-31');

        $array = $period->toArray();

        self::assertSame('2026-01-01', $array['start']);
        self::assertSame('2026-12-31', $array['end']);
    }

    #[Test]
    public function periodFromArrayRejectsNonStrings(): void
    {
        $period = Period::fromArray(['start' => 42, 'end' => false]);

        self::assertNull($period->start);
        self::assertNull($period->end);
    }
}
