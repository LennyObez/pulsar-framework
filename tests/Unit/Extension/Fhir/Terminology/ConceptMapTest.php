<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Terminology;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Terminology\ConceptMap;
use Pulsar\Extension\Fhir\Terminology\ConceptMapEntry;

#[CoversClass(ConceptMap::class)]
#[CoversClass(ConceptMapEntry::class)]
final class ConceptMapTest extends TestCase
{
    public function testTranslateCode(): void
    {
        $map = new ConceptMap(
            url: 'http://example.org/ConceptMap/icd10-to-snomed',
            name: 'ICD-10 to SNOMED',
            sourceSystem: 'http://hl7.org/fhir/sid/icd-10',
            targetSystem: 'http://snomed.info/sct',
        );

        $map->addMapping('J06.9', '195662009', 'equivalent', 'Acute upper respiratory infection');
        $map->addMapping('E11', '44054006', 'equivalent', 'Type 2 diabetes mellitus');

        $results = $map->translate('J06.9');

        self::assertCount(1, $results);
        self::assertSame('J06.9', $results[0]->sourceCode);
        self::assertSame('195662009', $results[0]->targetCode);
        self::assertSame('equivalent', $results[0]->equivalence);
    }

    public function testTranslateNonexistentCode(): void
    {
        $map = new ConceptMap(
            url: 'http://example.org/ConceptMap/test',
            name: 'Test',
            sourceSystem: 'http://system-a',
            targetSystem: 'http://system-b',
        );

        $results = $map->translate('nonexistent');

        self::assertSame([], $results);
    }

    public function testMultipleTargetsForOneSource(): void
    {
        $map = new ConceptMap(
            url: 'http://example.org/ConceptMap/multi',
            name: 'Multi-target',
            sourceSystem: 'http://system-a',
            targetSystem: 'http://system-b',
        );

        $map->addMapping('A1', 'B1', 'equivalent');
        $map->addMapping('A1', 'B2', 'wider');

        $results = $map->translate('A1');

        self::assertCount(2, $results);
        self::assertSame('B1', $results[0]->targetCode);
        self::assertSame('B2', $results[1]->targetCode);
    }

    public function testHasMapping(): void
    {
        $map = new ConceptMap(
            url: 'http://example.org/test',
            name: 'Test',
            sourceSystem: 'http://sys',
            targetSystem: 'http://target',
        );

        $map->addMapping('A1', 'B1');

        self::assertTrue($map->hasMapping('A1'));
        self::assertFalse($map->hasMapping('A2'));
    }

    public function testConceptMapEntryToArray(): void
    {
        $entry = new ConceptMapEntry(
            sourceCode: 'J06.9',
            targetCode: '195662009',
            equivalence: 'equivalent',
            comment: 'Direct equivalent',
        );

        $array = $entry->toArray();

        self::assertSame('J06.9', $array['sourceCode']);
        self::assertSame('195662009', $array['targetCode']);
        self::assertSame('Direct equivalent', $array['comment']);
    }

    public function testConceptMapEntryToArrayOmitsEmptyComment(): void
    {
        $entry = new ConceptMapEntry('A', 'B');

        $array = $entry->toArray();

        self::assertArrayNotHasKey('comment', $array);
    }
}
