<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Serialization\MsgpackSerializer;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;
use Pulsar\Queue\Serialization\TypeRegistry;

use function extension_loaded;

#[CoversClass(MsgpackSerializer::class)]
final class MsgpackSerializerTest extends TestCase
{
    #[Test]
    public function constructorThrowsWhenMsgpackNotLoaded(): void
    {
        if (extension_loaded('msgpack')) {
            self::markTestSkipped('Test requires msgpack extension to NOT be loaded');
        }

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('msgpack extension is not loaded');

        new MsgpackSerializer(
            typeRegistry: new TypeRegistry(),
            schemaRegistry: new SchemaVersionRegistry(),
        );
    }

    #[Test]
    public function serializeProducesMessagePackOutput(): void
    {
        if (!extension_loaded('msgpack')) {
            self::markTestSkipped('Test requires the msgpack PHP extension');
        }

        $serializer = new MsgpackSerializer(
            typeRegistry: new TypeRegistry(),
            schemaRegistry: new SchemaVersionRegistry(),
        );

        $data = ['user_id' => 42, 'action' => 'process'];
        $packed = $serializer->serialize($data);

        self::assertNotEmpty($packed);
        self::assertNotSame('', $packed);
    }

    #[Test]
    public function serializeWrapsScalarInArray(): void
    {
        if (!extension_loaded('msgpack')) {
            self::markTestSkipped('Test requires the msgpack PHP extension');
        }

        $serializer = new MsgpackSerializer(
            typeRegistry: new TypeRegistry(),
            schemaRegistry: new SchemaVersionRegistry(),
        );

        $packed = $serializer->serialize('simple-string');

        self::assertNotSame('', $packed);

        $unpacked = msgpack_unpack($packed);
        self::assertIsArray($unpacked);
        self::assertSame('simple-string', $unpacked['_value']);
    }

    #[Test]
    public function deserializeReturnsArrayForValidType(): void
    {
        if (!extension_loaded('msgpack')) {
            self::markTestSkipped('Test requires the msgpack PHP extension');
        }

        $typeRegistry = new TypeRegistry();
        $typeRegistry->register('App\\Job\\TestJob');

        $schemaRegistry = new SchemaVersionRegistry();
        $schemaRegistry->register('App\\Job\\TestJob', 1);

        $serializer = new MsgpackSerializer(
            typeRegistry: $typeRegistry,
            schemaRegistry: $schemaRegistry,
        );

        $original = ['user_id' => 99, 'email' => 'test@example.com'];
        $packed = $serializer->serialize($original);

        $result = $serializer->deserialize($packed, 'App\\Job\\TestJob');

        self::assertSame(99, $result['user_id']);
        self::assertSame('test@example.com', $result['email']);
    }

    #[Test]
    public function contentTypeReturnsMsgpack(): void
    {
        if (!extension_loaded('msgpack')) {
            self::markTestSkipped('Test requires the msgpack PHP extension');
        }

        $serializer = new MsgpackSerializer(
            typeRegistry: new TypeRegistry(),
            schemaRegistry: new SchemaVersionRegistry(),
        );

        self::assertSame('application/x-msgpack', $serializer->contentType());
    }
}
