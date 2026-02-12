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
        self::assertSame('SCALAR', $intro['type']['kind']);
        self::assertSame('String', $intro['type']['name']);
    }

    #[Test]
    public function toIntrospectionWrapsInNonNullWhenRequired(): void
    {
        $arg = new ArgumentDefinition('id', 'ID', nonNull: true);

        $intro = $arg->toIntrospection();

        self::assertSame('NON_NULL', $intro['type']['kind']);
        self::assertSame('SCALAR', $intro['type']['ofType']['kind']);
        self::assertSame('ID', $intro['type']['ofType']['name']);
    }

    #[Test]
    public function toIntrospectionReturnsObjectKindForCustomType(): void
    {
        $arg = new ArgumentDefinition('input', 'ContentInput');

        $intro = $arg->toIntrospection();

        self::assertSame('OBJECT', $intro['type']['kind']);
        self::assertSame('ContentInput', $intro['type']['name']);
    }
}
