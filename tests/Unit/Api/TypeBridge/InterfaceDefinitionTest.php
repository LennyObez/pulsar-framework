<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\InterfaceDefinition;
use Pulsar\Api\TypeBridge\PropertyDefinition;

#[CoversClass(InterfaceDefinition::class)]
final class InterfaceDefinitionTest extends TestCase
{
    #[Test]
    public function constructorStoresNameAndProperties(): void
    {
        $prop = new PropertyDefinition('id', 'number');
        $def = new InterfaceDefinition('User', [$prop]);

        self::assertSame('User', $def->name);
        self::assertCount(1, $def->properties);
        self::assertSame('id', $def->properties[0]->name);
        self::assertSame('number', $def->properties[0]->typeScriptType);
    }

    #[Test]
    public function emptyPropertiesListIsValid(): void
    {
        $def = new InterfaceDefinition('EmptyInterface', []);

        self::assertSame('EmptyInterface', $def->name);
        self::assertSame([], $def->properties);
    }

    #[Test]
    public function multiplePropertiesAreMaintained(): void
    {
        $props = [
            new PropertyDefinition('name', 'string'),
            new PropertyDefinition('age', 'number', optional: true),
            new PropertyDefinition('active', 'boolean'),
        ];

        $def = new InterfaceDefinition('UserProfile', $props);

        self::assertCount(3, $def->properties);
        self::assertTrue($def->properties[1]->optional);
    }
}
