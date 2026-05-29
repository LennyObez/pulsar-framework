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
final class PhpCacheSerializerTest extends TestCase
{
    private PhpCacheSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpCacheSerializer();
    }

    #[Test]
    public function roundTripString(): void
    {
        $encoded = $this->serializer->serialize('hello');
        self::assertSame('hello', $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function roundTripInt(): void
    {
        $encoded = $this->serializer->serialize(42);
        self::assertSame(42, $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function roundTripFloat(): void
    {
        $encoded = $this->serializer->serialize(3.14);
        self::assertSame(3.14, $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function roundTripNull(): void
    {
        $encoded = $this->serializer->serialize(null);
        self::assertNull($this->serializer->deserialize($encoded));
    }

    #[Test]
    public function roundTripBool(): void
    {
        $encodedTrue = $this->serializer->serialize(true);
        self::assertTrue($this->serializer->deserialize($encodedTrue));

        $encodedFalse = $this->serializer->serialize(false);
        self::assertFalse($this->serializer->deserialize($encodedFalse));
    }

    #[Test]
    public function roundTripArray(): void
    {
        $data = ['a' => 1, 'b' => 'two', 'c' => [3, 4]];
        $encoded = $this->serializer->serialize($data);
        self::assertSame($data, $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function objectSerializationWithAllowlist(): void
    {
        $serializer = new PhpCacheSerializer([stdClass::class]);
        $object = new stdClass();
        $object->name = 'test';

        $encoded = $serializer->serialize($object);
        $decoded = $serializer->deserialize($encoded);

        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertSame('test', $decoded->name);
    }

    #[Test]
    public function deserializeWithoutAllowlistRejectsObjects(): void
    {
        $serializerWithAllowlist = new PhpCacheSerializer([stdClass::class]);
        $object = new stdClass();
        $object->name = 'test';
        $encoded = $serializerWithAllowlist->serialize($object);

        $serializerWithoutAllowlist = new PhpCacheSerializer();

        // Fail closed: a class outside the allowlist must be rejected, not
        // silently downgraded to __PHP_Incomplete_Class and handed back.
        $this->expectException(CacheException::class);
        $serializerWithoutAllowlist->deserialize($encoded);
    }

    #[Test]
    public function invalidDataThrowsCacheException(): void
    {
        $this->expectException(CacheException::class);

        @$this->serializer->deserialize('not-valid-serialized-data');
    }

    #[Test]
    public function serializeFalseRoundTripsCorrectly(): void
    {
        $encoded = $this->serializer->serialize(false);

        self::assertSame('b:0;', $encoded);

        $decoded = $this->serializer->deserialize($encoded);

        self::assertFalse($decoded);
    }
}
