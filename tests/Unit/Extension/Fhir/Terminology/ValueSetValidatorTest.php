<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Terminology;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Terminology\ConceptDefinition;
use Pulsar\Extension\Fhir\Terminology\ValueSetDefinition;
use Pulsar\Extension\Fhir\Terminology\ValueSetInclude;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

#[CoversClass(ValueSetValidator::class)]
final class ValueSetValidatorTest extends TestCase
{
    public function testValidateCodeInValueSet(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.org/ValueSet/gender',
            name: 'Gender',
            includes: [
                new ValueSetInclude(
                    system: 'http://hl7.org/fhir/administrative-gender',
                    concepts: [
                        new ConceptDefinition('male', 'Male'),
                        new ConceptDefinition('female', 'Female'),
                        new ConceptDefinition('other', 'Other'),
                        new ConceptDefinition('unknown', 'Unknown'),
                    ],
                ),
            ],
        ));

        $result = $validator->validate(
            'http://example.org/ValueSet/gender',
            'http://hl7.org/fhir/administrative-gender',
            'male',
        );

        self::assertTrue($result->valid);
        self::assertSame('Male', $result->display);
    }

    public function testValidateInvalidCode(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.org/ValueSet/gender',
            name: 'Gender',
            includes: [
                new ValueSetInclude(
                    system: 'http://hl7.org/fhir/administrative-gender',
                    concepts: [
                        new ConceptDefinition('male', 'Male'),
                    ],
                ),
            ],
        ));

        $result = $validator->validate(
            'http://example.org/ValueSet/gender',
            'http://hl7.org/fhir/administrative-gender',
            'invalid',
        );

        self::assertFalse($result->valid);
        self::assertNotNull($result->message);
    }

    public function testValidateUnknownValueSet(): void
    {
        $validator = new ValueSetValidator();

        $result = $validator->validate('http://unknown', 'http://sys', 'code');

        self::assertFalse($result->valid);
        self::assertStringContainsString('not found', $result->message ?? '');
    }

    public function testValidateWrongSystem(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.org/ValueSet/vs1',
            name: 'VS1',
            includes: [
                new ValueSetInclude(
                    system: 'http://system-a',
                    concepts: [new ConceptDefinition('A1', 'Code A1')],
                ),
            ],
        ));

        $result = $validator->validate(
            'http://example.org/ValueSet/vs1',
            'http://system-b',
            'A1',
        );

        self::assertFalse($result->valid);
    }

    public function testValidateEntireSystemIncluded(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.org/ValueSet/all-snomed',
            name: 'All SNOMED',
            includes: [
                new ValueSetInclude(system: 'http://snomed.info/sct'),
            ],
        ));

        $result = $validator->validate(
            'http://example.org/ValueSet/all-snomed',
            'http://snomed.info/sct',
            '12345678',
        );

        self::assertTrue($result->valid);
    }

    public function testGetRegisteredValueSet(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(
            url: 'http://example.org/vs',
            name: 'Test',
        );
        $validator->register($vs);

        self::assertSame($vs, $validator->get('http://example.org/vs'));
        self::assertNull($validator->get('http://unknown'));
    }
}
