<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use stdClass;

#[CoversClass(JsonCacheSerializer::class)]
final class JsonCacheSerializerExtendedTest extends TestCase
{
    private JsonCacheSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonCacheSerializer();
    }

    #[Test]
    #[DataProvider('scalarValues')]
    public function serializeAndDeserializeScalarsRoundTrip(mixed $value): void
    {
        $json = $this->serializer->serialize($value);
        $result = $this->serializer->deserialize($json);

        self::assertSame($value, $result);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function scalarValues(): iterable
    {
        yield 'string' => ['hello'];
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'null' => [null];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'negative int' => [-1];
    }

    #[Test]
    public function serializeArrayRoundTrips(): void
    {
        $data = ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]];
        $json = $this->serializer->serialize($data);
        $result = $this->serializer->deserialize($json);

        self::assertSame($data, $result);
    }

    #[Test]
    public function serializeObjectThrowsCacheException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('JSON');

        $this->serializer->serialize(new stdClass());
    }

    #[Test]
    public function deserializeInvalidJsonThrowsCacheException(): void
    {
        $this->expectException(CacheException::class);

        $this->serializer->deserialize('{invalid json}}}');
    }

    #[Test]
    public function serializePreservesZeroFraction(): void
    {
        $json = $this->serializer->serialize(1.0);

        self::assertStringContainsString('.0', $json);
    }

    #[Test]
    public function serializeHandlesUnicodeCharacters(): void
    {
        $value = ['name' => 'Rene'];
        $json = $this->serializer->serialize($value);
        $result = $this->serializer->deserialize($json);

        self::assertSame($value, $result);
    }

    #[Test]
    public function serializeHandlesSlashes(): void
    {
        $value = 'path/to/something';
        $json = $this->serializer->serialize($value);

        // Should use unescaped slashes
        self::assertStringNotContainsString('\\/', $json);
    }
}
