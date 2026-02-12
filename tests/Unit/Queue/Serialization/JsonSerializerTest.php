<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Serialization\JsonSerializer;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Serialization\VersionTransformerInterface;

#[CoversClass(JsonSerializer::class)]
final class JsonSerializerTest extends TestCase
{
    private TypeRegistry $typeRegistry;
    private SchemaVersionRegistry $schemaRegistry;

    protected function setUp(): void
    {
        $this->typeRegistry = new TypeRegistry();
        $this->typeRegistry->register('App\\Jobs\\SendEmail');

        $this->schemaRegistry = new SchemaVersionRegistry();
    }

    #[Test]
    public function serializeArrayData(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $result = $serializer->serialize(['name' => 'test', 'count' => 42]);

        self::assertJson($result);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true);
        self::assertSame('test', $decoded['name']);
        self::assertSame(42, $decoded['count']);
    }

    #[Test]
    public function serializeNonArrayWrapsInValueKey(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $result = $serializer->serialize('hello');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true);
        self::assertSame('hello', $decoded['_value']);
    }

    #[Test]
    public function deserializeValidData(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $result = $serializer->deserialize('{"name":"test"}', 'App\\Jobs\\SendEmail');

        self::assertSame('test', $result['name']);
    }

    #[Test]
    public function deserializeThrowsForUnregisteredType(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not registered');

        $serializer->deserialize('{}', 'App\\Jobs\\Unregistered');
    }

    #[Test]
    public function deserializeThrowsForNonArrayJson(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('serialize/deserialize');

        $serializer->deserialize('"just a string"', 'App\\Jobs\\SendEmail');
    }

    #[Test]
    public function contentTypeReturnsApplicationJson(): void
    {
        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        self::assertSame('application/json', $serializer->contentType());
    }

    #[Test]
    public function deserializeWithVersionMismatchUsesTransformer(): void
    {
        $this->schemaRegistry->register('App\\Jobs\\SendEmail', 2);

        $transformer = $this->createStub(VersionTransformerInterface::class);
        $transformer->method('supports')->willReturn(true);
        $transformer->method('transform')->willReturn(['migrated' => true, '_schema_version' => 2]);

        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry, [$transformer]);

        $result = $serializer->deserialize('{"_schema_version":1,"old":"data"}', 'App\\Jobs\\SendEmail');

        self::assertTrue($result['migrated']);
    }

    #[Test]
    public function deserializeWithIncompatibleVersionThrows(): void
    {
        $this->schemaRegistry->register('App\\Jobs\\SendEmail', 2);

        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not compatible');

        $serializer->deserialize('{"_schema_version":3}', 'App\\Jobs\\SendEmail');
    }

    #[Test]
    public function deserializeWithNoSupportingTransformerThrows(): void
    {
        $this->schemaRegistry->register('App\\Jobs\\SendEmail', 2);

        $transformer = $this->createStub(VersionTransformerInterface::class);
        $transformer->method('supports')->willReturn(false);

        $serializer = new JsonSerializer($this->typeRegistry, $this->schemaRegistry, [$transformer]);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not compatible');

        $serializer->deserialize('{"_schema_version":1}', 'App\\Jobs\\SendEmail');
    }
}
