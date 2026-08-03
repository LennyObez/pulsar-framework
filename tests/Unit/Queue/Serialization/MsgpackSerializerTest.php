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
use function ini_get;
use function ini_set;
use function msgpack_pack;

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
        $this->expectExceptionMessageIsOrContains('msgpack extension is not loaded');

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

    #[Test]
    public function deserializeRejectsAnObjectInThePayload(): void
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

        // A tampered/hostile payload carrying a PHP object nested in the data.
        // With msgpack.php_only=On, msgpack_unpack() would reconstruct the object;
        // the serializer must reject it fail-closed, matching JsonSerializer's
        // pure-data contract (CWE-502 object-injection defense). Force php_only=On
        // so the test is deterministic regardless of the runtime default.
        $original = ini_get('msgpack.php_only');
        ini_set('msgpack.php_only', '1');

        try {
            $hostile = msgpack_pack(['_value' => (object) ['x' => 1]]);

            $this->expectException(QueueException::class);
            $this->expectExceptionMessageIsOrContains('must be pure data');

            $serializer->deserialize($hostile, 'App\\Job\\TestJob');
        } finally {
            ini_set('msgpack.php_only', $original === false ? '1' : $original);
        }
    }
}
