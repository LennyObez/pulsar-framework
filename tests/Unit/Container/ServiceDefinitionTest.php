<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\DecoratorDefinition;
use Pulsar\Container\Lifetime;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\TagDefinition;
use stdClass;

#[CoversClass(ServiceDefinition::class)]
#[CoversClass(TagDefinition::class)]
#[CoversClass(DecoratorDefinition::class)]
final class ServiceDefinitionTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $def = new ServiceDefinition(id: 'foo', concrete: stdClass::class);

        self::assertSame('foo', $def->id);
        self::assertSame(stdClass::class, $def->concrete);
        self::assertSame(Lifetime::Singleton, $def->lifetime);
        self::assertSame([], $def->tags);
        self::assertFalse($def->lazy);
        self::assertSame([], $def->decorators);
        self::assertNull($def->contextFor);
    }

    #[Test]
    public function constructsWithAllParameters(): void
    {
        $tag = new TagDefinition('event.listener', 10, ['event' => 'user.created']);
        $decorator = new DecoratorDefinition(stdClass::class, 5);

        $def = new ServiceDefinition(
            id: 'bar',
            concrete: fn() => new stdClass(),
            lifetime: Lifetime::RequestScope,
            tags: [$tag],
            lazy: true,
            decorators: [$decorator],
            contextFor: 'Consumer',
        );

        self::assertSame('bar', $def->id);
        self::assertSame(Lifetime::RequestScope, $def->lifetime);
        self::assertCount(1, $def->tags);
        self::assertTrue($def->lazy);
        self::assertCount(1, $def->decorators);
        self::assertSame('Consumer', $def->contextFor);
    }

    #[Test]
    public function withTagsAppendsImmutably(): void
    {
        $def = new ServiceDefinition(id: 'foo', concrete: stdClass::class);
        $tag = new TagDefinition('cache.pool');

        $updated = $def->withTags($tag);

        self::assertSame([], $def->tags);
        self::assertCount(1, $updated->tags);
        self::assertSame('cache.pool', $updated->tags[0]->name);
    }

    #[Test]
    public function withDecoratorsAppendsImmutably(): void
    {
        $def = new ServiceDefinition(id: 'foo', concrete: stdClass::class);
        $decorator = new DecoratorDefinition(stdClass::class, 10);

        $updated = $def->withDecorators($decorator);

        self::assertSame([], $def->decorators);
        self::assertCount(1, $updated->decorators);
        self::assertSame(10, $updated->decorators[0]->priority);
    }

    #[Test]
    public function withLazySetsFlag(): void
    {
        $def = new ServiceDefinition(id: 'foo', concrete: stdClass::class);

        $lazy = $def->withLazy();
        $notLazy = $lazy->withLazy(false);

        self::assertFalse($def->lazy);
        self::assertTrue($lazy->lazy);
        self::assertFalse($notLazy->lazy);
    }

    #[Test]
    public function tagDefinitionStoresMetadata(): void
    {
        $tag = new TagDefinition('event.listener', 42, ['event' => 'user.login']);

        self::assertSame('event.listener', $tag->name);
        self::assertSame(42, $tag->priority);
        self::assertSame(['event' => 'user.login'], $tag->attributes);
    }

    #[Test]
    public function decoratorDefinitionStoresMetadata(): void
    {
        $decorator = new DecoratorDefinition(stdClass::class, 5);

        self::assertSame(stdClass::class, $decorator->decorator);
        self::assertSame(5, $decorator->priority);
    }
}
