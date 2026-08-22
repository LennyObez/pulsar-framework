<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\PropertyDefinition;

#[CoversClass(PropertyDefinition::class)]
final class PropertyDefinitionTest extends TestCase
{
    #[Test]
    public function defaultTypeIsUnknown(): void
    {
        $prop = new PropertyDefinition('data');

        self::assertSame('data', $prop->name);
        self::assertSame('unknown', $prop->typeScriptType);
        self::assertFalse($prop->optional);
    }

    #[Test]
    public function customTypeAndOptionalFlag(): void
    {
        $prop = new PropertyDefinition('email', 'string', optional: true);

        self::assertSame('email', $prop->name);
        self::assertSame('string', $prop->typeScriptType);
        self::assertTrue($prop->optional);
    }
}
