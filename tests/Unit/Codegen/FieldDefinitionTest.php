<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\FieldDefinition;

#[CoversClass(FieldDefinition::class)]
final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $field = new FieldDefinition(
            name: 'email',
            type: 'string',
            nullable: true,
            primary: false,
        );

        self::assertSame('email', $field->name);
        self::assertSame('string', $field->type);
        self::assertTrue($field->nullable);
        self::assertFalse($field->primary);
    }

    #[Test]
    public function defaultValues(): void
    {
        $field = new FieldDefinition(name: 'id', type: 'int');

        self::assertFalse($field->nullable);
        self::assertFalse($field->primary);
    }

    #[Test]
    public function primaryFieldCanBeSet(): void
    {
        $field = new FieldDefinition(name: 'id', type: 'int', primary: true);

        self::assertTrue($field->primary);
    }
}
