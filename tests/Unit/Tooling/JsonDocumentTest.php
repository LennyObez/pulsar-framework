<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\JsonDocument;
use RuntimeException;

/**
 * The tooling JSON reader exists to make malformed input fail loudly, so the
 * tests that matter most are the ones asserting it refuses rather than degrades.
 */
#[CoversClass(JsonDocument::class)]
final class JsonDocumentTest extends TestCase
{
    #[Test]
    public function readsTypedScalars(): void
    {
        $doc = JsonDocument::fromString('{"name":"pulsar","count":3,"ratio":1.5}', 'test');

        self::assertSame('pulsar', $doc->string('name'));
        self::assertSame(3, $doc->int('count'));
        self::assertSame(1.5, $doc->floatOr('ratio', 0.0));
        self::assertSame(3.0, $doc->floatOr('count', 0.0), 'an int is a valid float');
        self::assertTrue($doc->has('name'));
        self::assertFalse($doc->has('absent'));
        self::assertSame('test', $doc->source());
    }

    #[Test]
    public function fallsBackOnlyWhereADefaultWasOffered(): void
    {
        $doc = JsonDocument::fromString('{"name":42,"label":"text"}', 'test');

        self::assertSame('fallback', $doc->stringOr('name', 'fallback'), 'wrong type takes the default');
        self::assertSame('fallback', $doc->stringOr('absent', 'fallback'));
        self::assertSame(7, $doc->intOr('label', 7), 'wrong type takes the default');
        self::assertSame(42, $doc->intOr('name', 7), 'a right-typed value is never replaced');
        self::assertSame(0.0, $doc->floatOr('label', 0.0));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test.name is not a string.');
        $doc->string('name');
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.lock is not valid JSON');

        JsonDocument::fromString('{not json', 'composer.lock');
    }

    #[Test]
    public function rejectsAScalarDocument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not decode to a JSON object or array');

        JsonDocument::fromString('"just a string"', 'baseline.json');
    }

    #[Test]
    public function rejectsAnUnreadableFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read JSON from');

        JsonDocument::fromFile(__DIR__ . '/does-not-exist.json');
    }

    #[Test]
    public function filtersStringMapsAndListsToTheirStringEntries(): void
    {
        $doc = JsonDocument::fromString('{"require":{"php":">=8.5","ext-gd":"*","weird":7},"tags":["a",2,"b"]}', 'test');

        self::assertSame(['php' => '>=8.5', 'ext-gd' => '*'], $doc->stringMap('require'));
        self::assertSame(['a', 'b'], $doc->stringList('tags'));
        self::assertSame([], $doc->stringMap('absent'), 'an absent key is an empty map, not an error');
        self::assertSame([], $doc->stringList('absent'));
    }

    #[Test]
    public function walksListsOfObjects(): void
    {
        $doc = JsonDocument::fromString('{"violations":[{"file":"a.php","import":"X"},{"file":"b.php","import":"Y"}]}', 'baseline');
        $children = $doc->children('violations');

        self::assertCount(2, $children);
        self::assertSame('a.php', $children[0]->string('file'));
        self::assertSame('Y', $children[1]->string('import'));
        self::assertSame('baseline.violations[1]', $children[1]->source(), 'errors must name the offending index');
        self::assertSame([], $doc->children('absent'));
    }

    /**
     * The whole point: a stray scalar in a record list is a corrupt document, and
     * skipping it is how a ratchet silently loses a violation.
     */
    #[Test]
    public function refusesToSkipANonObjectInsideARecordList(): void
    {
        $doc = JsonDocument::fromString('{"violations":[{"file":"a.php"},"oops"]}', 'baseline');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('baseline.violations[1] is not an object.');

        $doc->children('violations');
    }

    #[Test]
    public function refusesAnObjectWhereAListOfObjectsIsExpected(): void
    {
        $doc = JsonDocument::fromString('{"violations":{"a":{"file":"a.php"}}}', 'baseline');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('baseline.violations is an object, not a list of objects.');

        $doc->children('violations');
    }

    #[Test]
    public function walksObjectMaps(): void
    {
        $doc = JsonDocument::fromString('{"packages":{"psr/log":{"version":"3.0.0"},"psr/cache":{"version":"3.0.0"}}}', 'lock');
        $map = $doc->childMap('packages');

        self::assertSame(['psr/log', 'psr/cache'], array_keys($map));
        self::assertSame('3.0.0', $map['psr/log']->string('version'));
        self::assertSame('lock.packages.psr/log', $map['psr/log']->source());
    }

    #[Test]
    public function nestsIntoChildObjects(): void
    {
        $doc = JsonDocument::fromString('{"project":{"metrics":{"statements":10}}}', 'clover');

        self::assertSame(10, $doc->child('project')->child('metrics')->int('statements'));
        self::assertSame([], $doc->childOrEmpty('absent')->toArray());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('clover.absent is not an object.');
        $doc->child('absent');
    }

    #[Test]
    public function acceptsAnAlreadyDecodedArray(): void
    {
        $doc = JsonDocument::fromArray(['name' => 'pulsar'], 'inline');

        self::assertSame('pulsar', $doc->string('name'));
        self::assertSame(['name' => 'pulsar'], $doc->toArray());
    }
}
