<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\ArgumentDefinition;

final class ArgumentDefinitionTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $arg = new ArgumentDefinition('id', 'ID');

        self::assertSame('id', $arg->name);
        self::assertSame('ID', $arg->type);
        self::assertFalse($arg->nonNull);
        self::assertNull($arg->defaultValue);
    }

    #[Test]
    public function constructsWithAllFields(): void
    {
        $arg = new ArgumentDefinition('page', 'Int', nonNull: false, defaultValue: 1);

        self::assertSame('page', $arg->name);
        self::assertSame('Int', $arg->type);
        self::assertSame(1, $arg->defaultValue);
    }

    #[Test]
    public function toIntrospectionReturnsScalarTypeKind(): void
    {
        $arg = new ArgumentDefinition('name', 'String');

        $intro = $arg->toIntrospection();

        self::assertSame('name', $intro['name']);
        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('SCALAR', $type['kind']);
        self::assertSame('String', $type['name']);
    }

    #[Test]
    public function toIntrospectionWrapsInNonNullWhenRequired(): void
    {
        $arg = new ArgumentDefinition('id', 'ID', nonNull: true);

        $intro = $arg->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('NON_NULL', $type['kind']);
        /** @var array<string, mixed> $ofType */
        $ofType = $type['ofType'];
        self::assertSame('SCALAR', $ofType['kind']);
        self::assertSame('ID', $ofType['name']);
    }

    #[Test]
    public function toIntrospectionReturnsObjectKindForCustomType(): void
    {
        $arg = new ArgumentDefinition('input', 'ContentInput');

        $intro = $arg->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('OBJECT', $type['kind']);
        self::assertSame('ContentInput', $type['name']);
    }
}
