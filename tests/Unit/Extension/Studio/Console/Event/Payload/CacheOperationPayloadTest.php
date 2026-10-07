<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

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
        $payload = new CacheOperationPayload(
            operation: 'get',
            key: 'user:42',
            hit: true,
            durationMs: 0.5,
        );

        self::assertSame(EventType::CacheOperation, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new CacheOperationPayload(
            operation: 'set',
            key: 'session:abc',
            hit: false,
            durationMs: 1.2,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new CacheOperationPayload(
            operation: 'get',
            key: 'user:42',
            hit: true,
            durationMs: 0.3,
            store: 'redis',
            ttl: 3600,
        );

        $data = $payload->toArray();

        self::assertSame('get', $data['operation']);
        self::assertSame('user:42', $data['key']);
        self::assertTrue($data['hit']);
        self::assertSame(0.3, $data['duration_ms']);
        self::assertSame('redis', $data['store']);
        self::assertSame(3600, $data['ttl']);
    }
}
