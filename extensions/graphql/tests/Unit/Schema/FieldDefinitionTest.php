<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\ArgumentDefinition;
use Pulsar\Extension\Graphql\Schema\FieldDefinition;

final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function constructsSimpleField(): void
    {
        $field = new FieldDefinition('title', 'String');

        self::assertSame('title', $field->name);
        self::assertSame('String', $field->type);
        self::assertFalse($field->nonNull);
        self::assertFalse($field->isList);
        self::assertFalse($field->listItemNonNull);
        self::assertSame([], $field->arguments);
    }

    #[Test]
    public function toIntrospectionForSimpleScalar(): void
    {
        $field = new FieldDefinition('name', 'String');

        $intro = $field->toIntrospection();

        self::assertSame('name', $intro['name']);
        self::assertSame('SCALAR', $intro['type']['kind']);
        self::assertSame('String', $intro['type']['name']);
        self::assertSame([], $intro['args']);
    }

    #[Test]
    public function toIntrospectionForNonNullScalar(): void
    {
        $field = new FieldDefinition('id', 'ID', nonNull: true);

        $intro = $field->toIntrospection();

        self::assertSame('NON_NULL', $intro['type']['kind']);
        self::assertSame('ID', $intro['type']['ofType']['name']);
    }

    #[Test]
    public function toIntrospectionForListField(): void
    {
        $field = new FieldDefinition('tags', 'String', isList: true);

        $intro = $field->toIntrospection();

        self::assertSame('LIST', $intro['type']['kind']);
        self::assertSame('SCALAR', $intro['type']['ofType']['kind']);
    }

    #[Test]
    public function toIntrospectionForNonNullListOfNonNullItems(): void
    {
        $field = new FieldDefinition('items', 'Content', nonNull: true, isList: true, listItemNonNull: true);

        $intro = $field->toIntrospection();

        self::assertSame('NON_NULL', $intro['type']['kind']);
        self::assertSame('LIST', $intro['type']['ofType']['kind']);
        self::assertSame('NON_NULL', $intro['type']['ofType']['ofType']['kind']);
        self::assertSame('Content', $intro['type']['ofType']['ofType']['ofType']['name']);
    }

    #[Test]
    public function toIntrospectionIncludesArguments(): void
    {
        $field = new FieldDefinition(
            'content',
            'Content',
            arguments: [
                'id' => new ArgumentDefinition('id', 'ID', nonNull: true),
            ],
        );

        $intro = $field->toIntrospection();

        self::assertCount(1, $intro['args']);
        self::assertSame('id', $intro['args'][0]['name']);
    }

    #[Test]
    public function toIntrospectionUsesObjectKindForCustomType(): void
    {
        $field = new FieldDefinition('author', 'User');

        $intro = $field->toIntrospection();

        self::assertSame('OBJECT', $intro['type']['kind']);
    }
}
