<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\EntityTemplate;
use Pulsar\Codegen\FieldDefinition;

#[CoversClass(EntityTemplate::class)]
final class EntityTemplateTest extends TestCase
{
    #[Test]
    public function fieldsDefaultToEmpty(): void
    {
        $entity = new EntityTemplate(name: 'Post', namespace: 'App\\Models');

        self::assertSame([], $entity->fields);
    }

    #[Test]
    public function fieldDefinitionsAreAccessibleByIndex(): void
    {
        $fields = [
            new FieldDefinition('id', 'int', primary: true),
            new FieldDefinition('title', 'string'),
            new FieldDefinition('body', 'string', nullable: true),
        ];

        $entity = new EntityTemplate(
            name: 'Post',
            namespace: 'App\\Models',
            fields: $fields,
        );

        self::assertCount(3, $entity->fields);
        self::assertSame('id', $entity->fields[0]->name);
        self::assertTrue($entity->fields[0]->primary);
        self::assertSame('body', $entity->fields[2]->name);
        self::assertTrue($entity->fields[2]->nullable);
    }

    #[Test]
    public function entityWithBackslashNamespace(): void
    {
        $entity = new EntityTemplate(
            name: 'Invoice',
            namespace: 'App\\Billing\\Models',
        );

        self::assertSame('App\\Billing\\Models', $entity->namespace);
    }
}
