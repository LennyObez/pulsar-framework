<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $correlationId = CorrelationId::fromString(str_repeat('aa', 16));
        $causationId = CausationId::fromString(str_repeat('bb', 16));
        $timestamp = new DateTimeImmutable('2025-01-15T10:30:00+00:00');

        $context = new RequestContext(
            correlationId: $correlationId,
            causationId: $causationId,
            actor: 'user-123',
            tenantId: 'tenant-abc',
            ip: '192.168.1.1',
            userAgent: 'TestAgent/1.0',
            locale: 'en-US',
            timestamp: $timestamp,
            attributes: ['key' => 'value'],
        );

        self::assertSame($correlationId, $context->correlationId);
        self::assertSame($causationId, $context->causationId);
        self::assertSame('user-123', $context->actor);
        self::assertSame('tenant-abc', $context->tenantId);
        self::assertSame('192.168.1.1', $context->ip);
        self::assertSame('TestAgent/1.0', $context->userAgent);
        self::assertSame('en-US', $context->locale);
        self::assertSame($timestamp, $context->timestamp);
        self::assertSame(['key' => 'value'], $context->attributes);
    }

    #[Test]
    public function constructionWithDefaults(): void
    {
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        self::assertNull($context->actor);
        self::assertNull($context->tenantId);
        self::assertNull($context->ip);
        self::assertNull($context->userAgent);
        self::assertNull($context->locale);
        self::assertInstanceOf(DateTimeImmutable::class, $context->timestamp);
        self::assertSame([], $context->attributes);
    }

    #[Test]
    public function withActorReturnsNewInstance(): void
    {
        $context = $this->createContext();
        $updated = $context->withActor('admin-456');

        self::assertNull($context->actor);
        self::assertSame('admin-456', $updated->actor);
        self::assertSame($context->correlationId, $updated->correlationId);
    }

    #[Test]
    public function withTenantIdReturnsNewInstance(): void
    {
        $context = $this->createContext();
        $updated = $context->withTenantId('tenant-xyz');

        self::assertNull($context->tenantId);
        self::assertSame('tenant-xyz', $updated->tenantId);
    }

    #[Test]
    public function withAttributesReturnsNewInstance(): void
    {
        $context = $this->createContext();
        $updated = $context->withAttributes(['foo' => 'bar']);

        self::assertSame([], $context->attributes);
        self::assertSame(['foo' => 'bar'], $updated->attributes);
    }

    #[Test]
    public function toArrayFromArrayRoundtrip(): void
    {
        $timestamp = new DateTimeImmutable('2025-06-01T12:00:00.000000+00:00');
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
            actor: 'user-1',
            tenantId: 'tenant-1',
            ip: '10.0.0.1',
            userAgent: 'Agent/2.0',
            locale: 'de-DE',
            timestamp: $timestamp,
            attributes: ['custom' => 'data'],
        );

        $array = $context->toArray();
        $restored = RequestContext::fromArray($array);

        self::assertSame($context->correlationId->value, $restored->correlationId->value);
        self::assertSame($context->causationId->value, $restored->causationId->value);
        self::assertSame($context->actor, $restored->actor);
        self::assertSame($context->tenantId, $restored->tenantId);
        self::assertSame($context->ip, $restored->ip);
        self::assertSame($context->userAgent, $restored->userAgent);
        self::assertSame($context->locale, $restored->locale);
        self::assertSame(['custom' => 'data'], $restored->attributes);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $context = $this->createContext();
        $array = $context->toArray();

        self::assertArrayHasKey('correlation_id', $array);
        self::assertArrayHasKey('causation_id', $array);
        self::assertArrayHasKey('tenant_id', $array);
        self::assertArrayHasKey('user_agent', $array);
    }

    #[Test]
    public function fromArrayCoercesNonStringFieldsToNull(): void
    {
        $context = RequestContext::fromArray([
            'correlation_id' => str_repeat('aa', 16),
            'causation_id' => str_repeat('bb', 16),
            'actor' => 42,
            'tenant_id' => ['array'],
            'ip' => false,
            'user_agent' => 0,
            'locale' => null,
        ]);

        self::assertNull($context->actor);
        self::assertNull($context->tenantId);
        self::assertNull($context->ip);
        self::assertNull($context->userAgent);
        self::assertNull($context->locale);
    }

    #[Test]
    public function fromArrayThrowsForNonStringCorrelationId(): void
    {
        $this->expectException(\Pulsar\Context\Exception\ContextException::class);
        $this->expectExceptionMessage('Invalid correlation ID');

        (void) RequestContext::fromArray([
            'correlation_id' => 12345,
            'causation_id' => str_repeat('bb', 16),
        ]);
    }

    #[Test]
    public function fromArrayCreatesTimestampFromString(): void
    {
        $context = RequestContext::fromArray([
            'correlation_id' => str_repeat('cc', 16),
            'causation_id' => str_repeat('dd', 16),
            'timestamp' => '2025-06-01T12:00:00.000000+00:00',
        ]);

        self::assertSame('2025', $context->timestamp->format('Y'));
    }

    #[Test]
    public function fromArrayGeneratesTimestampWhenMissing(): void
    {
        $before = new DateTimeImmutable();

        $context = RequestContext::fromArray([
            'correlation_id' => str_repeat('cc', 16),
            'causation_id' => str_repeat('dd', 16),
        ]);

        self::assertGreaterThanOrEqual($before, $context->timestamp);
    }

    #[Test]
    public function fromArrayDefaultsAttributesToEmptyArray(): void
    {
        $context = RequestContext::fromArray([
            'correlation_id' => str_repeat('cc', 16),
            'causation_id' => str_repeat('dd', 16),
        ]);

        self::assertSame([], $context->attributes);
    }

    private function createContext(): RequestContext
    {
        return new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );
    }
}
