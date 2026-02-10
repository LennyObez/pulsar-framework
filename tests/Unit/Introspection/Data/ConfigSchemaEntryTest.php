<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ConfigPropertySchema;
use Pulsar\Introspection\Data\ConfigSchemaEntry;

#[CoversClass(ConfigSchemaEntry::class)]
final class ConfigSchemaEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $props = [
            new ConfigPropertySchema(name: 'host', type: 'string'),
        ];

        $entry = new ConfigSchemaEntry(
            className: 'App\\Config\\DbConfig',
            properties: $props,
        );

        self::assertSame('App\\Config\\DbConfig', $entry->className);
        self::assertCount(1, $entry->properties);
    }

    #[Test]
    public function constructorDefaultsToEmptyProperties(): void
    {
        $entry = new ConfigSchemaEntry(className: 'App\\Config\\Empty');

        self::assertSame([], $entry->properties);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $entry = new ConfigSchemaEntry(
            className: 'App\\Config\\AppConfig',
            properties: [
                new ConfigPropertySchema(name: 'debug', type: 'bool', default: false),
                new ConfigPropertySchema(name: 'name', type: 'string', default: 'Pulsar'),
            ],
        );

        $array = $entry->toArray();

        self::assertSame('App\\Config\\AppConfig', $array['class']);
        self::assertCount(2, $array['properties']);
        self::assertSame('debug', $array['properties'][0]['name']);
        self::assertSame('name', $array['properties'][1]['name']);
    }
}
