<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ConfigPropertySchema;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\ConfigSchemaEntry;

#[CoversClass(ConfigSchemaData::class)]
final class ConfigSchemaDataTest extends TestCase
{
    #[Test]
    public function constructorDefaultsToEmpty(): void
    {
        $data = new ConfigSchemaData();

        self::assertSame([], $data->schemas);
    }

    #[Test]
    public function toArraySerializesSchemas(): void
    {
        $entry = new ConfigSchemaEntry(
            className: 'App\\Config\\DatabaseConfig',
            properties: [
                new ConfigPropertySchema(name: 'host', type: 'string', default: 'localhost'),
            ],
        );

        $data = new ConfigSchemaData(schemas: [$entry]);
        $array = $data->toArray();

        self::assertArrayHasKey('schemas', $array);
        self::assertCount(1, $array['schemas']);
        self::assertSame('App\\Config\\DatabaseConfig', $array['schemas'][0]['class']);
        self::assertSame('host', $array['schemas'][0]['properties'][0]['name']);
    }
}
