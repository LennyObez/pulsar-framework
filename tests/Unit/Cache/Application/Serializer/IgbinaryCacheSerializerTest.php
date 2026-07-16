<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\IgbinaryCacheSerializer;
use stdClass;

use function extension_loaded;
use function igbinary_serialize;

#[CoversClass(IgbinaryCacheSerializer::class)]
final class IgbinaryCacheSerializerTest extends TestCase
{
    private IgbinaryCacheSerializer $serializer;

    protected function setUp(): void
    {
        if (!extension_loaded('igbinary')) {
            self::markTestSkipped('ext-igbinary is not loaded');
        }

        $this->serializer = new IgbinaryCacheSerializer();
    }

    #[Test]
    public function roundTripsScalarsAndArraysWithKeyAndFloatFidelity(): void
    {
        $value = [
            0 => 'int key preserved',
            42 => 3.25,
            'nested' => [true, null, 'text', -7],
        ];

        $decoded = $this->serializer->deserialize($this->serializer->serialize($value));

        self::assertSame($value, $decoded);
    }

    #[Test]
    public function roundTripsNull(): void
    {
        self::assertNull($this->serializer->deserialize($this->serializer->serialize(null)));
    }

    #[Test]
    public function rejectsTopLevelObjectsOnSerialize(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('scalars and arrays only');

        $this->serializer->serialize(new stdClass());
    }

    #[Test]
    public function rejectsObjectsNestedInsideArraysOnSerialize(): void
    {
        // json_encode would flatten a nested object losslessly; igbinary would
        // round-trip it as a live object with __wakeup — so nesting must be
        // refused, not just the top level.
        $this->expectException(CacheException::class);

        $this->serializer->serialize(['a' => ['b' => new stdClass()]]);
    }

    #[Test]
    public function rejectsForeignBlobsContainingObjectsOnDeserialize(): void
    {
        // A blob written by something else into a shared backend can encode an
        // object; the data-only rule must hold on the read path so the object
        // never reaches a consumer.
        $foreign = igbinary_serialize(['payload' => new stdClass()]);
        self::assertIsString($foreign);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('data-only');

        $this->serializer->deserialize($foreign);
    }

    #[Test]
    public function throwsOnGarbageInput(): void
    {
        $this->expectException(CacheException::class);

        $this->serializer->deserialize('not igbinary data');
    }
}
