<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\CacheOperationPayload;

#[CoversClass(CacheOperationPayload::class)]
final class CacheOperationPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsCacheOperation(): void
    {
        self::assertSame(EventType::CacheOperation, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('get', $array['operation']);
        self::assertSame('user:123', $array['key']);
        self::assertTrue($array['hit']);
        self::assertSame(0.5, $array['duration_ms']);
        self::assertSame('redis', $array['store']);
        self::assertSame(3600, $array['ttl']);
    }

    #[Test]
    public function defaultStoreAndTtlAreNull(): void
    {
        $payload = new CacheOperationPayload(
            operation: 'delete',
            key: 'session:abc',
            hit: false,
            durationMs: 0.1,
        );

        $array = $payload->toArray();

        self::assertNull($array['store']);
        self::assertNull($array['ttl']);
    }

    #[Test]
    public function cacheMissRepresentation(): void
    {
        $payload = new CacheOperationPayload(
            operation: 'get',
            key: 'missing-key',
            hit: false,
            durationMs: 1.2,
            store: 'file',
        );

        self::assertFalse($payload->hit);
        self::assertSame('file', $payload->store);
        self::assertNull($payload->ttl);
    }

    private function createPayload(): CacheOperationPayload
    {
        return new CacheOperationPayload(
            operation: 'get',
            key: 'user:123',
            hit: true,
            durationMs: 0.5,
            store: 'redis',
            ttl: 3600,
        );
    }
}
