<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Serializer;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;

#[CoversNothing]
final class CacheSerializerInterfaceTest extends TestCase
{
    private function createJsonSerializer(): CacheSerializerInterface
    {
        return new class implements CacheSerializerInterface {
            public function serialize(mixed $value): string
            {
                $result = json_encode($value, JSON_THROW_ON_ERROR);
                if ($result === false) {
                    throw CacheException::serializationFailed('JSON encode failed');
                }

                return $result;
            }

            public function deserialize(string $data): mixed
            {
                return json_decode($data, true, 64, JSON_THROW_ON_ERROR);
            }
        };
    }

    #[Test]
    public function serializeAndDeserializeRoundTrip(): void
    {
        $serializer = $this->createJsonSerializer();

        $data = ['name' => 'test', 'count' => 42, 'nested' => ['a' => 1]];
        $serialized = $serializer->serialize($data);
        $deserialized = $serializer->deserialize($serialized);

        self::assertSame($data, $deserialized);
    }

    #[Test]
    #[DataProvider('scalarValueProvider')]
    public function serializesScalarValues(mixed $input): void
    {
        $serializer = $this->createJsonSerializer();

        $serialized = $serializer->serialize($input);
        $deserialized = $serializer->deserialize($serialized);

        self::assertSame($input, $deserialized);
    }

    /** @return iterable<string, array{mixed}> */
    public static function scalarValueProvider(): iterable
    {
        yield 'string' => ['hello world'];
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
    }

    #[Test]
    public function serializeReturnsNonEmptyString(): void
    {
        $serializer = $this->createJsonSerializer();

        $result = $serializer->serialize(['key' => 'value']);

        self::assertNotEmpty($result);
        self::assertStringContainsString('key', $result);
    }

    #[Test]
    public function deserializeHandlesEmptyArray(): void
    {
        $serializer = $this->createJsonSerializer();

        $serialized = $serializer->serialize([]);
        $deserialized = $serializer->deserialize($serialized);

        self::assertSame([], $deserialized);
    }
}
