<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use stdClass;

#[CoversClass(JsonCacheSerializer::class)]
final class JsonCacheSerializerTest extends TestCase
{
    private JsonCacheSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonCacheSerializer();
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
        $data = ['a' => 1, 'b' => 'two'];
        $encoded = $this->serializer->serialize($data);
        self::assertSame($data, $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function roundTripNestedArray(): void
    {
        $data = ['level1' => ['level2' => ['level3' => 'deep']]];
        $encoded = $this->serializer->serialize($data);
        self::assertSame($data, $this->serializer->deserialize($encoded));
    }

    #[Test]
    public function objectThrowsCacheExceptionWithActionableMessage(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageMatches("/serializer: 'php'/");

        $this->serializer->serialize(new stdClass());
    }

    #[Test]
    public function invalidJsonOnDeserializeThrowsCacheException(): void
    {
        $this->expectException(CacheException::class);

        $this->serializer->deserialize('{invalid json');
    }
}
