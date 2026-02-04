<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Event\EventMetadata;

#[CoversClass(EventMetadata::class)]
final class EventMetadataTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $correlationId = CorrelationId::fromString(str_repeat('aa', 16));
        $causationId = CausationId::fromString(str_repeat('bb', 16));
        $occurredAt = new DateTimeImmutable('2025-06-01T12:00:00+00:00');

        $metadata = new EventMetadata(
            correlationId: $correlationId,
            causationId: $causationId,
            actor: 'user-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
            attributes: ['key' => 'value'],
        );

        self::assertSame($correlationId, $metadata->correlationId);
        self::assertSame($causationId, $metadata->causationId);
        self::assertSame('user-1', $metadata->actor);
        self::assertSame('tenant-1', $metadata->tenantId);
        self::assertSame($occurredAt, $metadata->occurredAt);
        self::assertSame(['key' => 'value'], $metadata->attributes);
    }

    #[Test]
    public function constructionDefaultsTimestamp(): void
    {
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $metadata->occurredAt);
    }

    #[Test]
    public function fromRequestContext(): void
    {
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
            actor: 'user-2',
            tenantId: 'tenant-2',
        );

        $metadata = EventMetadata::fromRequestContext($context);

        self::assertSame($context->correlationId, $metadata->correlationId);
        self::assertSame($context->causationId, $metadata->causationId);
        self::assertSame('user-2', $metadata->actor);
        self::assertSame('tenant-2', $metadata->tenantId);
    }

    #[Test]
    public function toArrayFromArrayRoundtrip(): void
    {
        $occurredAt = new DateTimeImmutable('2025-06-01T12:00:00.000000+00:00');
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
            actor: 'user-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
            attributes: ['custom' => 'data'],
        );

        $array = $metadata->toArray();
        $restored = EventMetadata::fromArray($array);

        self::assertSame($metadata->correlationId->value, $restored->correlationId->value);
        self::assertSame($metadata->causationId->value, $restored->causationId->value);
        self::assertSame($metadata->actor, $restored->actor);
        self::assertSame($metadata->tenantId, $restored->tenantId);
        self::assertSame(['custom' => 'data'], $restored->attributes);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        $array = $metadata->toArray();

        self::assertArrayHasKey('correlation_id', $array);
        self::assertArrayHasKey('causation_id', $array);
        self::assertArrayHasKey('tenant_id', $array);
        self::assertArrayHasKey('occurred_at', $array);
    }
}
