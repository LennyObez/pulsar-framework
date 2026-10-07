<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\TenancyPayload;

#[CoversClass(TenancyPayload::class)]
final class TenancyPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsHeartbeat(): void
    {
        self::assertSame(EventType::Heartbeat, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createPayload()->toArray();

        self::assertSame('hash-abc-123', $array['tenant_hash']);
        self::assertSame('subdomain', $array['resolver_strategy']);
        self::assertTrue($array['resolved']);
    }

    #[Test]
    public function unresolvedTenant(): void
    {
        $payload = new TenancyPayload(
            tenantHash: '',
            resolverStrategy: 'header',
            resolved: false,
        );

        $array = $payload->toArray();

        self::assertSame('', $array['tenant_hash']);
        self::assertFalse($array['resolved']);
        self::assertSame('header', $array['resolver_strategy']);
    }

    private function createPayload(): TenancyPayload
    {
        return new TenancyPayload(
            tenantHash: 'hash-abc-123',
            resolverStrategy: 'subdomain',
            resolved: true,
        );
    }
}
