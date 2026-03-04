<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\PhpCacheSerializer;
use stdClass;

#[CoversClass(PhpCacheSerializer::class)]
final class PhpCacheSerializerExtendedTest extends TestCase
{
    #[Test]
    public function serializeAndDeserializeScalarRoundTrips(): void
    {
        $serializer = new PhpCacheSerializer();

        $data = $serializer->serialize('test-string');
        $result = $serializer->deserialize($data);

        self::assertSame('test-string', $result);
    }

    #[Test]
    public function serializeAndDeserializeArrayRoundTrips(): void
    {
        $serializer = new PhpCacheSerializer();
        $value = ['key' => 'value', 'nested' => [1, 2, 3]];

        $data = $serializer->serialize($value);
        $result = $serializer->deserialize($data);

        self::assertSame($value, $result);
    }

    #[Test]
    public function serializeAndDeserializeFalseRoundTrips(): void
    {
        $serializer = new PhpCacheSerializer();

        $data = $serializer->serialize(false);
        $result = $serializer->deserialize($data);

        self::assertFalse($result);
    }

    #[Test]
    public function deserializeInvalidDataThrowsCacheException(): void
    {
        $serializer = new PhpCacheSerializer();

        $this->expectException(CacheException::class);

        $serializer->deserialize('this-is-not-valid-serialized-data');
    }

    #[Test]
    public function deserializeWithoutAllowedClassesBlocksObjects(): void
    {
        $serializer = new PhpCacheSerializer();

        // Serialize an stdClass but deserialize without allowlist
        $data = serialize(new stdClass());

        $this->expectException(CacheException::class);

        $serializer->deserialize($data);
    }

    #[Test]
    public function defaultAllowedClassesIsEmpty(): void
    {
        // Creating without arguments means no classes are allowed
        $serializer = new PhpCacheSerializer();

        // But scalars should still work
        $data = $serializer->serialize(42);
        $result = $serializer->deserialize($data);

        self::assertSame(42, $result);
    }

    #[Test]
    public function serializeNullRoundTrips(): void
    {
        $serializer = new PhpCacheSerializer();

        $data = $serializer->serialize(null);
        $result = $serializer->deserialize($data);

        self::assertNull($result);
    }
}
