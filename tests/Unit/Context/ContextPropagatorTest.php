<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\ContextPropagator;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;

#[CoversClass(ContextPropagator::class)]
final class ContextPropagatorTest extends TestCase
{
    #[Test]
    public function injectAndExtractRoundtrip(): void
    {
        $timestamp = new DateTimeImmutable('2025-06-01T12:00:00.000000+00:00');
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
            actor: 'user-1',
            tenantId: 'tenant-1',
            ip: '10.0.0.1',
            userAgent: 'Agent/1.0',
            locale: 'en-US',
            timestamp: $timestamp,
            attributes: ['custom' => 'data'],
        );

        $carrier = [];
        ContextPropagator::inject($context, $carrier);

        $extracted = ContextPropagator::extract($carrier);

        self::assertNotNull($extracted);
        self::assertSame($context->correlationId->value, $extracted->correlationId->value);
        self::assertSame($context->causationId->value, $extracted->causationId->value);
        self::assertSame('user-1', $extracted->actor);
        self::assertSame('tenant-1', $extracted->tenantId);
        self::assertSame('10.0.0.1', $extracted->ip);
        self::assertSame('Agent/1.0', $extracted->userAgent);
        self::assertSame('en-US', $extracted->locale);
        self::assertSame(['custom' => 'data'], $extracted->attributes);
    }

    #[Test]
    public function extractReturnsNullForEmptyCarrier(): void
    {
        self::assertNull(ContextPropagator::extract([]));
    }

    #[Test]
    public function extractReturnsNullForMissingCorrelationId(): void
    {
        self::assertNull(ContextPropagator::extract(['some_key' => 'value']));
    }

    #[Test]
    public function extractReturnsNullForInvalidData(): void
    {
        self::assertNull(ContextPropagator::extract([
            '_ctx_correlation_id' => 'not-valid-hex',
            '_ctx_causation_id' => str_repeat('bb', 16),
        ]));
    }

    #[Test]
    public function injectPreservesExistingCarrierKeys(): void
    {
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        $carrier = ['existing_key' => 'existing_value'];
        ContextPropagator::inject($context, $carrier);

        self::assertSame('existing_value', $carrier['existing_key']);
        self::assertArrayHasKey('_ctx_correlation_id', $carrier);
    }

    #[Test]
    public function extractHandlesNullOptionalFields(): void
    {
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        $carrier = [];
        ContextPropagator::inject($context, $carrier);

        $extracted = ContextPropagator::extract($carrier);

        self::assertNotNull($extracted);
        self::assertNull($extracted->actor);
        self::assertNull($extracted->tenantId);
        self::assertNull($extracted->ip);
        self::assertNull($extracted->userAgent);
        self::assertNull($extracted->locale);
    }
}
