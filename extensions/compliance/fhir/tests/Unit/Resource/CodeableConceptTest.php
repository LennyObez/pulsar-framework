<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;

#[CoversClass(CodeableConcept::class)]
final class CodeableConceptTest extends TestCase
{
    #[Test]
    public function minimalToArrayReturnsEmpty(): void
    {
        $cc = new CodeableConcept();

        self::assertSame([], $cc->toArray());
    }

    #[Test]
    public function toArrayWithCodingAndText(): void
    {
        $cc = new CodeableConcept(
            coding: [
                new Coding(system: 'http://snomed.info/sct', code: '38341003', display: 'Hypertension'),
                new Coding(system: 'http://hl7.org/fhir/sid/icd-10', code: 'I10'),
            ],
            text: 'Essential hypertension',
        );

        $array = $cc->toArray();

        self::assertCount(2, $array['coding']);
        self::assertSame('38341003', $array['coding'][0]['code']);
        self::assertSame('I10', $array['coding'][1]['code']);
        self::assertSame('Essential hypertension', $array['text']);
    }

    #[Test]
    public function toArrayWithTextOnly(): void
    {
        $cc = new CodeableConcept(text: 'Free text diagnosis');

        $array = $cc->toArray();

        self::assertSame('Free text diagnosis', $array['text']);
        self::assertArrayNotHasKey('coding', $array);
    }

    #[Test]
    public function fromArrayWithMultipleCodings(): void
    {
        $data = [
            'coding' => [
                ['system' => 'http://loinc.org', 'code' => '8867-4'],
                ['code' => 'HR'],
            ],
            'text' => 'Heart rate',
        ];

        $cc = CodeableConcept::fromArray($data);

        self::assertCount(2, $cc->coding);
        self::assertSame('8867-4', $cc->coding[0]->code);
        self::assertSame('HR', $cc->coding[1]->code);
        self::assertSame('Heart rate', $cc->text);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $cc = CodeableConcept::fromArray([]);

        self::assertSame([], $cc->coding);
        self::assertNull($cc->text);
    }

    #[Test]
    public function fromArrayRejectsNonStringText(): void
    {
        $cc = CodeableConcept::fromArray(['text' => 42]);

        self::assertNull($cc->text);
    }
}
