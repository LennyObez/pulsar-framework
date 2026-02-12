<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Tag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\Tag\TagCollector;
use Pulsar\Container\TagDefinition;
use stdClass;

#[CoversClass(TagCollector::class)]
final class TagCollectorTest extends TestCase
{
    #[Test]
    public function collectsMatchingDefinitions(): void
    {
        $definitions = [
            'a' => new ServiceDefinition(
                id: 'a',
                concrete: stdClass::class,
                tags: [new TagDefinition('event.listener', 10)],
            ),
            'b' => new ServiceDefinition(
                id: 'b',
                concrete: stdClass::class,
                tags: [new TagDefinition('event.listener', 20)],
            ),
            'c' => new ServiceDefinition(
                id: 'c',
                concrete: stdClass::class,
                tags: [new TagDefinition('cache.pool')],
            ),
        ];

        $result = TagCollector::collect('event.listener', $definitions);

        self::assertCount(2, $result);
        self::assertSame('b', $result[0]->id); // priority 20 first
        self::assertSame('a', $result[1]->id); // priority 10 second
    }

    #[Test]
    public function returnsEmptyForNoMatches(): void
    {
        $definitions = [
            'a' => new ServiceDefinition(
                id: 'a',
                concrete: stdClass::class,
                tags: [new TagDefinition('cache.pool')],
            ),
        ];

        $result = TagCollector::collect('nonexistent', $definitions);

        self::assertSame([], $result);
    }

    #[Test]
    public function sortsDeterministicallyByPriorityThenId(): void
    {
        $definitions = [
            'z' => new ServiceDefinition(
                id: 'z',
                concrete: stdClass::class,
                tags: [new TagDefinition('tag', 5)],
            ),
            'a' => new ServiceDefinition(
                id: 'a',
                concrete: stdClass::class,
                tags: [new TagDefinition('tag', 5)],
            ),
            'b' => new ServiceDefinition(
                id: 'b',
                concrete: stdClass::class,
                tags: [new TagDefinition('tag', 10)],
            ),
        ];

        $result = TagCollector::collect('tag', $definitions);

        self::assertSame('b', $result[0]->id); // priority 10
        self::assertSame('a', $result[1]->id); // priority 5, id 'a' < 'z'
        self::assertSame('z', $result[2]->id); // priority 5, id 'z'
    }

    #[Test]
    public function collectIdsReturnsSortedServiceIds(): void
    {
        $definitions = [
            'z' => new ServiceDefinition(
                id: 'z',
                concrete: stdClass::class,
                tags: [new TagDefinition('tag', 5)],
            ),
            'a' => new ServiceDefinition(
                id: 'a',
                concrete: stdClass::class,
                tags: [new TagDefinition('tag', 10)],
            ),
        ];

        $result = TagCollector::collectIds('tag', $definitions);

        self::assertSame(['a', 'z'], $result);
    }

    #[Test]
    public function collectIdsReturnsEmptyForNoMatches(): void
    {
        $definitions = [
            'a' => new ServiceDefinition(
                id: 'a',
                concrete: stdClass::class,
            ),
        ];

        $result = TagCollector::collectIds('nonexistent', $definitions);

        self::assertSame([], $result);
    }
}
