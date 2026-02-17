<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Terminology;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Terminology\ConceptDefinition;
use Pulsar\Extension\Fhir\Terminology\ConceptMap;
use Pulsar\Extension\Fhir\Terminology\ConceptMapEntry;
use Pulsar\Extension\Fhir\Terminology\ValueSetDefinition;
use Pulsar\Extension\Fhir\Terminology\ValueSetInclude;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

#[CoversClass(ConceptMap::class)]
#[CoversClass(ConceptMapEntry::class)]
#[CoversClass(ConceptDefinition::class)]
#[CoversClass(ValueSetDefinition::class)]
#[CoversClass(ValueSetInclude::class)]
#[CoversClass(ValueSetValidator::class)]
final class ConceptMapTest extends TestCase
{
    // --- ConceptMap ---

    #[Test]
    public function conceptMapTranslatesSourceToTarget(): void
    {
        $map = new ConceptMap(
            'http://example.org/map/icd-to-snomed',
            'ICD-10 to SNOMED-CT',
            'http://hl7.org/fhir/sid/icd-10',
            'http://snomed.info/sct',
        );

        $map->addMapping('E11', '44054006', 'equivalent', 'Type 2 diabetes');

        $results = $map->translate('E11');

        self::assertCount(1, $results);
        self::assertSame('E11', $results[0]->sourceCode);
        self::assertSame('44054006', $results[0]->targetCode);
        self::assertSame('equivalent', $results[0]->equivalence);
        self::assertSame('Type 2 diabetes', $results[0]->comment);
    }

    #[Test]
    public function conceptMapTranslateReturnsEmptyForUnknownCode(): void
    {
        $map = new ConceptMap('url', 'name', 'src', 'tgt');

        self::assertSame([], $map->translate('unknown'));
    }

    #[Test]
    public function conceptMapHasMappingReturnsTrueForMappedCode(): void
    {
        $map = new ConceptMap('url', 'name', 'src', 'tgt');
        $map->addMapping('A01', 'B01');

        self::assertTrue($map->hasMapping('A01'));
        self::assertFalse($map->hasMapping('A02'));
    }

    #[Test]
    public function conceptMapMultipleMappingsForSameSource(): void
    {
        $map = new ConceptMap('url', 'name', 'http://src', 'http://tgt');
        $map->addMapping('A01', 'B01', 'equivalent');
        $map->addMapping('A01', 'B02', 'narrower');

        $results = $map->translate('A01');

        self::assertCount(2, $results);
        self::assertSame('B01', $results[0]->targetCode);
        self::assertSame('B02', $results[1]->targetCode);
    }

    // --- ConceptMapEntry ---

    #[Test]
    public function conceptMapEntryToArrayWithoutComment(): void
    {
        $entry = new ConceptMapEntry('A01', 'B01');
        $array = $entry->toArray();

        self::assertSame('A01', $array['sourceCode']);
        self::assertSame('B01', $array['targetCode']);
        self::assertSame('equivalent', $array['equivalence']);
        self::assertArrayNotHasKey('comment', $array);
    }

    #[Test]
    public function conceptMapEntryToArrayWithComment(): void
    {
        $entry = new ConceptMapEntry('A01', 'B01', 'narrower', 'B01 is more specific');
        $array = $entry->toArray();

        self::assertSame('B01 is more specific', $array['comment']);
    }

    // --- ConceptDefinition ---

    #[Test]
    public function conceptDefinitionToArrayWithMinimalFields(): void
    {
        $def = new ConceptDefinition('M', 'Male');
        $array = $def->toArray();

        self::assertSame('M', $array['code']);
        self::assertSame('Male', $array['display']);
        self::assertArrayNotHasKey('definition', $array);
        self::assertArrayNotHasKey('designation', $array);
    }

    #[Test]
    public function conceptDefinitionToArrayWithDefinitionAndDesignations(): void
    {
        $def = new ConceptDefinition('M', 'Male', 'Male gender', ['fr' => 'Masculin', 'de' => 'Mannlich']);
        $array = $def->toArray();

        self::assertSame('Male gender', $array['definition']);
        self::assertCount(2, $array['designation']);
        self::assertSame('fr', $array['designation'][0]['language']);
        self::assertSame('Masculin', $array['designation'][0]['value']);
    }

    // --- ValueSetDefinition ---

    #[Test]
    public function valueSetDefinitionConstructsCorrectly(): void
    {
        $include = new ValueSetInclude('http://loinc.org', '2.72', [
            new ConceptDefinition('1234-5', 'Test code'),
        ]);

        $vs = new ValueSetDefinition(
            'http://example.org/valueset/test',
            'Test VS',
            'active',
            [$include],
        );

        self::assertSame('http://example.org/valueset/test', $vs->url);
        self::assertSame('Test VS', $vs->name);
        self::assertSame('active', $vs->status);
        self::assertCount(1, $vs->includes);
    }

    // --- ValueSetInclude ---

    #[Test]
    public function valueSetIncludeDefaultsToNullVersionAndEmptyConcepts(): void
    {
        $include = new ValueSetInclude('http://snomed.info/sct');

        self::assertNull($include->version);
        self::assertSame([], $include->concepts);
    }

    // --- ValueSetValidator (deep tests) ---

    #[Test]
    public function validateReturnsInvalidForUnregisteredValueSet(): void
    {
        $validator = new ValueSetValidator();

        $result = $validator->validate('http://nonexistent', 'http://sys', 'code');

        self::assertFalse($result->valid);
        self::assertStringContainsString('not found', $result->message ?? '');
    }

    #[Test]
    public function validateAcceptsEntireSystemWhenNoConceptsListed(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(
            'http://example.org/vs/all',
            'All codes',
            'active',
            [new ValueSetInclude('http://loinc.org')],
        );
        $validator->register($vs);

        $result = $validator->validate('http://example.org/vs/all', 'http://loinc.org', 'any-code');

        self::assertTrue($result->valid);
    }

    #[Test]
    public function validateRejectsCodeFromWrongSystem(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(
            'http://example.org/vs/test',
            'Test',
            'active',
            [new ValueSetInclude('http://snomed.info/sct', null, [
                new ConceptDefinition('123', 'Test concept'),
            ])],
        );
        $validator->register($vs);

        $result = $validator->validate('http://example.org/vs/test', 'http://wrong-system', '123');

        self::assertFalse($result->valid);
    }

    #[Test]
    public function validateAcceptsKnownConceptInSystem(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(
            'http://example.org/vs/gender',
            'Gender',
            'active',
            [new ValueSetInclude('http://hl7.org/fhir/administrative-gender', null, [
                new ConceptDefinition('male', 'Male'),
                new ConceptDefinition('female', 'Female'),
            ])],
        );
        $validator->register($vs);

        $result = $validator->validate(
            'http://example.org/vs/gender',
            'http://hl7.org/fhir/administrative-gender',
            'male',
        );

        self::assertTrue($result->valid);
        self::assertSame('Male', $result->display);
    }

    #[Test]
    public function validateRejectsUnknownConceptInExplicitList(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(
            'http://example.org/vs/gender',
            'Gender',
            'active',
            [new ValueSetInclude('http://hl7.org/fhir/administrative-gender', null, [
                new ConceptDefinition('male', 'Male'),
            ])],
        );
        $validator->register($vs);

        $result = $validator->validate(
            'http://example.org/vs/gender',
            'http://hl7.org/fhir/administrative-gender',
            'unknown-code',
        );

        self::assertFalse($result->valid);
    }

    #[Test]
    public function getReturnsRegisteredValueSet(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition('http://example.org/vs/1', 'Test');
        $validator->register($vs);

        self::assertSame($vs, $validator->get('http://example.org/vs/1'));
    }

    #[Test]
    public function getReturnsNullForUnregisteredUrl(): void
    {
        $validator = new ValueSetValidator();

        self::assertNull($validator->get('http://nonexistent'));
    }
}
