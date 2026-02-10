<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ConfigPropertySchema;

#[CoversClass(ConfigPropertySchema::class)]
final class ConfigPropertySchemaTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $schema = new ConfigPropertySchema(
            name: 'host',
            type: 'string',
            default: 'localhost',
        );

        self::assertSame('host', $schema->name);
        self::assertSame('string', $schema->type);
        self::assertSame('localhost', $schema->default);
    }

    #[Test]
    public function constructorDefaultsToNullDefault(): void
    {
        $schema = new ConfigPropertySchema(
            name: 'timeout',
            type: 'int',
        );

        self::assertNull($schema->default);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $schema = new ConfigPropertySchema(
            name: 'debug',
            type: 'bool',
            default: false,
        );

        $array = $schema->toArray();

        self::assertSame('debug', $array['name']);
        self::assertSame('bool', $array['type']);
        self::assertFalse($array['default']);
    }
}
