<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Schema\FieldDefinition;
use Pulsar\Extension\Graphql\Schema\ObjectType;

final class ObjectTypeTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndFields(): void
    {
        $type = new ObjectType('Content', [
            'id' => new FieldDefinition('id', 'ID', nonNull: true),
            'title' => new FieldDefinition('title', 'String', nonNull: true),
        ]);

        self::assertSame('Content', $type->name);
        self::assertCount(2, $type->fields);
        self::assertSame('id', $type->fields['id']->name);
    }

    #[Test]
    public function toIntrospectionReturnsObjectKind(): void
    {
        $type = new ObjectType('Media', [
            'filename' => new FieldDefinition('filename', 'String', nonNull: true),
        ]);

        $intro = $type->toIntrospection();

        self::assertSame('OBJECT', $intro['kind']);
        self::assertSame('Media', $intro['name']);
        self::assertCount(1, $intro['fields']);
        self::assertSame('filename', $intro['fields'][0]['name']);
    }
}
