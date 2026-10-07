<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Terminology;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\TerminologyService;
use Pulsar\Extension\Fhir\Terminology\CodeSystemInterface;
use Pulsar\Extension\Fhir\Terminology\ConceptDefinition;
use Pulsar\Extension\Fhir\Terminology\ConceptMap;
use Pulsar\Extension\Fhir\Terminology\ConceptMapEntry;
use Pulsar\Extension\Fhir\Terminology\ValueSetDefinition;
use Pulsar\Extension\Fhir\Terminology\ValueSetInclude;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

#[CoversClass(TerminologyService::class)]
#[CoversClass(ConceptMap::class)]
#[CoversClass(ConceptMapEntry::class)]
#[CoversClass(ConceptDefinition::class)]
#[CoversClass(ValueSetValidator::class)]
#[CoversClass(ValueSetDefinition::class)]
#[CoversClass(ValueSetInclude::class)]
final class TerminologyServiceTest extends TestCase
{
    #[Test]
    public function lookupReturnsNullForUnknownSystem(): void
    {
        $service = new TerminologyService(new ValueSetValidator());

        self::assertNull($service->lookup('http://unknown', 'ABC'));
    }

    #[Test]
    public function lookupDelegatesToCodeSystem(): void
    {
        $concept = new ConceptDefinition(code: 'E11', display: 'Type 2 diabetes mellitus');

        $codeSystem = $this->createStub(CodeSystemInterface::class);
        $codeSystem->method('url')->willReturn('http://hl7.org/fhir/sid/icd-10');
        $codeSystem->method('lookup')->willReturn($concept);

        $service = new TerminologyService(new ValueSetValidator());
        $service->registerCodeSystem($codeSystem);

        $found = $service->lookup('http://hl7.org/fhir/sid/icd-10', 'E11');
        self::assertNotNull($found);
        self::assertSame('E11', $found->code);
        self::assertSame('Type 2 diabetes mellitus', $found->display);
    }

    #[Test]
    public function lookupReturnsNullWhenCodeNotFound(): void
    {
        $codeSystem = $this->createStub(CodeSystemInterface::class);
        $codeSystem->method('url')->willReturn('http://snomed.info/sct');
        $codeSystem->method('lookup')->willReturn(null);

        $service = new TerminologyService(new ValueSetValidator());
        $service->registerCodeSystem($codeSystem);

        self::assertNull($service->lookup('http://snomed.info/sct', 'NONEXISTENT'));
    }

    #[Test]
    public function translateReturnsEmptyForUnknownConceptMap(): void
    {
        $service = new TerminologyService(new ValueSetValidator());

        self::assertSame([], $service->translate('http://unknown/map', 'ABC'));
    }

    #[Test]
    public function translateDelegatesToConceptMap(): void
    {
        $conceptMap = new ConceptMap(
            url: 'http://example.com/icd10-to-snomed',
            name: 'ICD-10 to SNOMED',
            sourceSystem: 'http://hl7.org/fhir/sid/icd-10',
            targetSystem: 'http://snomed.info/sct',
        );
        $conceptMap->addMapping('E11', '44054006', 'equivalent', 'Type 2 diabetes');

        $service = new TerminologyService(new ValueSetValidator());
        $service->registerConceptMap($conceptMap);

        $results = $service->translate('http://example.com/icd10-to-snomed', 'E11');

        self::assertCount(1, $results);
        self::assertSame('E11', $results[0]->sourceCode);
        self::assertSame('44054006', $results[0]->targetCode);
    }

    #[Test]
    public function validateDelegatesToValueSetValidator(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.com/vs/gender',
            name: 'Gender codes',
            includes: [
                new ValueSetInclude(
                    system: 'http://hl7.org/fhir/administrative-gender',
                    concepts: [
                        new ConceptDefinition(code: 'male', display: 'Male'),
                        new ConceptDefinition(code: 'female', display: 'Female'),
                    ],
                ),
            ],
        ));

        $service = new TerminologyService($validator);

        $result = $service->validate(
            'http://example.com/vs/gender',
            'http://hl7.org/fhir/administrative-gender',
            'male',
        );

        self::assertTrue($result->valid);
        self::assertSame('Male', $result->display);
    }

    // --- ConceptMap behavioral tests ---

    #[Test]
    public function conceptMapTranslateReturnsMultipleTargets(): void
    {
        $map = new ConceptMap(
            url: 'http://test/map',
            name: 'Test',
            sourceSystem: 'http://source',
            targetSystem: 'http://target',
        );
        $map->addMapping('A1', 'B1', 'equivalent');
        $map->addMapping('A1', 'B2', 'wider');

        $results = $map->translate('A1');
        self::assertCount(2, $results);
        self::assertSame('B1', $results[0]->targetCode);
        self::assertSame('B2', $results[1]->targetCode);
    }

    #[Test]
    public function conceptMapTranslateReturnsEmptyForUnmappedCode(): void
    {
        $map = new ConceptMap(
            url: 'http://test/map',
            name: 'Test',
            sourceSystem: 'http://source',
            targetSystem: 'http://target',
        );
        $map->addMapping('A1', 'B1');

        self::assertSame([], $map->translate('UNKNOWN'));
    }

    #[Test]
    public function conceptMapHasMapping(): void
    {
        $map = new ConceptMap(
            url: 'http://test/map',
            name: 'Test',
            sourceSystem: 'http://source',
            targetSystem: 'http://target',
        );
        $map->addMapping('A1', 'B1');

        self::assertTrue($map->hasMapping('A1'));
        self::assertFalse($map->hasMapping('A2'));
    }

    // --- ValueSetValidator behavioral tests ---

    #[Test]
    public function valueSetValidatorReturnsInvalidForUnknownValueSet(): void
    {
        $validator = new ValueSetValidator();

        $result = $validator->validate('http://unknown', 'http://system', 'code');

        self::assertFalse($result->valid);
        self::assertStringContainsString('not found', $result->message ?? '');
    }

    #[Test]
    public function valueSetValidatorRejectsCodeNotInValueSet(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.com/vs/status',
            name: 'Status codes',
            includes: [
                new ValueSetInclude(
                    system: 'http://hl7.org/fhir/observation-status',
                    concepts: [
                        new ConceptDefinition(code: 'final', display: 'Final'),
                    ],
                ),
            ],
        ));

        $result = $validator->validate(
            'http://example.com/vs/status',
            'http://hl7.org/fhir/observation-status',
            'preliminary',
        );

        self::assertFalse($result->valid);
    }

    #[Test]
    public function valueSetValidatorAcceptsEntireSystemWhenNoConcepts(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.com/vs/all-snomed',
            name: 'All SNOMED',
            includes: [
                new ValueSetInclude(system: 'http://snomed.info/sct'),
            ],
        ));

        $result = $validator->validate(
            'http://example.com/vs/all-snomed',
            'http://snomed.info/sct',
            'any-code-at-all',
        );

        self::assertTrue($result->valid);
    }

    #[Test]
    public function valueSetValidatorRejectsWrongSystem(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.com/vs/test',
            name: 'Test',
            includes: [
                new ValueSetInclude(
                    system: 'http://system-a',
                    concepts: [
                        new ConceptDefinition(code: 'X1', display: 'X One'),
                    ],
                ),
            ],
        ));

        $result = $validator->validate('http://example.com/vs/test', 'http://system-b', 'X1');

        self::assertFalse($result->valid);
    }

    #[Test]
    public function valueSetValidatorGetReturnsRegisteredValueSet(): void
    {
        $validator = new ValueSetValidator();
        $vs = new ValueSetDefinition(url: 'http://vs/test', name: 'Test');
        $validator->register($vs);

        self::assertSame($vs, $validator->get('http://vs/test'));
        self::assertNull($validator->get('http://vs/unknown'));
    }

    // --- ConceptDefinition behavioral tests ---

    #[Test]
    public function conceptDefinitionToArrayMinimal(): void
    {
        $concept = new ConceptDefinition(code: 'E11', display: 'Diabetes mellitus type 2');
        $array = $concept->toArray();

        self::assertSame('E11', $array['code']);
        self::assertSame('Diabetes mellitus type 2', $array['display']);
        self::assertArrayNotHasKey('definition', $array);
        self::assertArrayNotHasKey('designation', $array);
    }

    #[Test]
    public function conceptDefinitionToArrayWithDefinitionAndDesignations(): void
    {
        $concept = new ConceptDefinition(
            code: 'E11',
            display: 'Type 2 diabetes mellitus',
            definition: 'A metabolic disease characterized by high blood sugar',
            designations: ['de' => 'Diabetes mellitus Typ 2', 'fr' => 'Diabete sucre de type 2'],
        );

        $array = $concept->toArray();

        self::assertSame('A metabolic disease characterized by high blood sugar', $array['definition']);
        self::assertCount(2, $array['designation']);
        self::assertSame('de', $array['designation'][0]['language']);
        self::assertSame('Diabetes mellitus Typ 2', $array['designation'][0]['value']);
    }

    // --- ConceptMapEntry tests ---

    #[Test]
    public function conceptMapEntryToArrayMinimal(): void
    {
        $entry = new ConceptMapEntry(sourceCode: 'A1', targetCode: 'B1');
        $array = $entry->toArray();

        self::assertSame('A1', $array['sourceCode']);
        self::assertSame('B1', $array['targetCode']);
        self::assertSame('equivalent', $array['equivalence']);
        self::assertArrayNotHasKey('comment', $array);
    }

    #[Test]
    public function conceptMapEntryToArrayWithComment(): void
    {
        $entry = new ConceptMapEntry(
            sourceCode: 'A1',
            targetCode: 'B1',
            equivalence: 'wider',
            comment: 'Broader mapping',
        );

        $array = $entry->toArray();
        self::assertSame('wider', $array['equivalence']);
        self::assertSame('Broader mapping', $array['comment']);
    }
}
