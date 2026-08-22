<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Extension\Fhir\Resource\Identifier;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    #[Test]
    public function minimalToArrayReturnsEmpty(): void
    {
        $id = new Identifier();

        self::assertSame([], $id->toArray());
    }

    #[Test]
    public function fullToArrayIncludesAllFields(): void
    {
        $id = new Identifier(
            use: 'official',
            type: new CodeableConcept(coding: [new Coding(code: 'MR')]),
            system: 'http://hospital.example.com/mrn',
            value: 'MRN-123456',
        );

        $array = $id->toArray();

        self::assertSame('official', $array['use']);
        self::assertSame('MR', $array['type']['coding'][0]['code']);
        self::assertSame('http://hospital.example.com/mrn', $array['system']);
        self::assertSame('MRN-123456', $array['value']);
    }

    #[Test]
    public function fromArrayRoundTrip(): void
    {
        $data = [
            'use' => 'usual',
            'type' => ['coding' => [['code' => 'SS']]],
            'system' => 'http://hl7.org/fhir/sid/us-ssn',
            'value' => '123-45-6789',
        ];

        $id = Identifier::fromArray($data);

        self::assertSame('usual', $id->use);
        self::assertNotNull($id->type);
        self::assertSame('SS', $id->type->coding[0]->code);
        self::assertSame('http://hl7.org/fhir/sid/us-ssn', $id->system);
        self::assertSame('123-45-6789', $id->value);
    }

    #[Test]
    public function fromArrayRejectsNonStringValues(): void
    {
        $id = Identifier::fromArray([
            'use' => 42,
            'system' => false,
            'value' => ['array'],
        ]);

        self::assertNull($id->use);
        self::assertNull($id->system);
        self::assertNull($id->value);
    }

    #[Test]
    public function fromArrayEmptyReturnsDefaults(): void
    {
        $id = Identifier::fromArray([]);

        self::assertNull($id->use);
        self::assertNull($id->type);
        self::assertNull($id->system);
        self::assertNull($id->value);
    }
}
