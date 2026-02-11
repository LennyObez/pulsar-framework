<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Factory\FactoryMap;
use RuntimeException;
use stdClass;

#[CoversClass(FactoryMap::class)]
final class FactoryMapTest extends TestCase
{
    #[Test]
    public function resolve_returns_factory_class(): void
    {
        $map = new FactoryMap([
            stdClass::class => UserFactory::class,
        ]);

        self::assertSame(UserFactory::class, $map->resolve(stdClass::class));
    }

    #[Test]
    public function resolve_throws_for_unknown_entity(): void
    {
        $map = new FactoryMap();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No factory registered');

        $map->resolve(RuntimeException::class);
    }

    #[Test]
    public function has_returns_true_for_registered(): void
    {
        $map = new FactoryMap([
            stdClass::class => UserFactory::class,
        ]);

        self::assertTrue($map->has(stdClass::class));
    }

    #[Test]
    public function has_returns_false_for_unregistered(): void
    {
        $map = new FactoryMap();

        self::assertFalse($map->has(stdClass::class));
    }

    #[Test]
    public function all_returns_full_map(): void
    {
        $entries = [
            stdClass::class => UserFactory::class,
        ];

        $map = new FactoryMap($entries);

        self::assertSame($entries, $map->all());
    }

    #[Test]
    public function empty_map_returns_empty_array(): void
    {
        $map = new FactoryMap();

        self::assertSame([], $map->all());
    }
}
