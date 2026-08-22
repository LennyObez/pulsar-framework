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
        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('SCALAR', $type['kind']);
        self::assertSame('String', $type['name']);
        self::assertSame([], $intro['args']);
    }

    #[Test]
    public function toIntrospectionForNonNullScalar(): void
    {
        $field = new FieldDefinition('id', 'ID', nonNull: true);

        $intro = $field->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('NON_NULL', $type['kind']);
        /** @var array<string, mixed> $ofType */
        $ofType = $type['ofType'];
        self::assertSame('ID', $ofType['name']);
    }

    #[Test]
    public function toIntrospectionForListField(): void
    {
        $field = new FieldDefinition('tags', 'String', isList: true);

        $intro = $field->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('LIST', $type['kind']);
        /** @var array<string, mixed> $ofType */
        $ofType = $type['ofType'];
        self::assertSame('SCALAR', $ofType['kind']);
    }

    #[Test]
    public function toIntrospectionForNonNullListOfNonNullItems(): void
    {
        $field = new FieldDefinition('items', 'Content', nonNull: true, isList: true, listItemNonNull: true);

        $intro = $field->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('NON_NULL', $type['kind']);
        /** @var array<string, mixed> $listType */
        $listType = $type['ofType'];
        self::assertSame('LIST', $listType['kind']);
        /** @var array<string, mixed> $itemWrapper */
        $itemWrapper = $listType['ofType'];
        self::assertSame('NON_NULL', $itemWrapper['kind']);
        /** @var array<string, mixed> $itemType */
        $itemType = $itemWrapper['ofType'];
        self::assertSame('Content', $itemType['name']);
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

        /** @var list<array<string, mixed>> $args */
        $args = $intro['args'];
        self::assertCount(1, $args);
        self::assertSame('id', $args[0]['name']);
    }

    #[Test]
    public function toIntrospectionUsesObjectKindForCustomType(): void
    {
        $field = new FieldDefinition('author', 'User');

        $intro = $field->toIntrospection();

        /** @var array<string, mixed> $type */
        $type = $intro['type'];
        self::assertSame('OBJECT', $type['kind']);
    }
}
