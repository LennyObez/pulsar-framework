<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\EntityDefinition;
use Pulsar\Codegen\FieldDefinition;

#[CoversClass(EntityDefinition::class)]
final class EntityDefinitionTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $fields = [
            new FieldDefinition('id', 'int', primary: true),
            new FieldDefinition('name', 'string'),
        ];

        $entity = new EntityDefinition(
            name: 'User',
            namespace: 'App\\Models',
            fields: $fields,
        );

        self::assertSame('User', $entity->name);
        self::assertSame('App\\Models', $entity->namespace);
        self::assertCount(2, $entity->fields);
        self::assertSame('id', $entity->fields[0]->name);
    }

    #[Test]
    public function fieldsDefaultToEmpty(): void
    {
        $entity = new EntityDefinition(name: 'Post', namespace: 'App\\Models');

        self::assertSame([], $entity->fields);
    }
}
