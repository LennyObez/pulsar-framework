<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\ModuleEventDispatcher;
use Pulsar\Event\Internal\StormGuard;
use stdClass;

#[CoversClass(ModuleEventDispatcher::class)]
final class ModuleEventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        ListenerProvider::resetStaticCaches();
    }

    #[Test]
    public function dispatchStampsOriginModuleOnEnvelopeWithNullOrigin(): void
    {
        $provider = new ListenerProvider();
        $guard = new StormGuard(new StormProtectionConfig());
        $inner = new EventDispatcher($provider, $provider, $guard);

        $moduleDispatcher = new ModuleEventDispatcher($inner, 'billing');

        $envelope = $this->createEnvelope();
        self::assertNull($envelope->originModule);

        /** @var EventEnvelope $result */
        $result = $moduleDispatcher->dispatch($envelope);

        self::assertSame('billing', $result->originModule);
    }

    #[Test]
    public function dispatchOverridesExistingOriginModule(): void
    {
        $provider = new ListenerProvider();
        $guard = new StormGuard(new StormProtectionConfig());
        $inner = new EventDispatcher($provider, $provider, $guard);

        $moduleDispatcher = new ModuleEventDispatcher($inner, 'billing');

        $envelope = $this->createEnvelope(originModule: 'shipping');

        /** @var EventEnvelope $result */
        $result = $moduleDispatcher->dispatch($envelope);

        // ModuleEventDispatcher always stamps — overrides existing originModule
        self::assertSame('billing', $result->originModule);
    }

    #[Test]
    public function dispatchPassesThroughNonEnvelopeEvents(): void
    {
        $provider = new ListenerProvider();
        $guard = new StormGuard(new StormProtectionConfig());
        $inner = new EventDispatcher($provider, $provider, $guard);

        $moduleDispatcher = new ModuleEventDispatcher($inner, 'billing');

        $event = new stdClass();
        $result = $moduleDispatcher->dispatch($event);

        self::assertSame($event, $result);
    }

    #[Test]
    public function dispatchEnvelopeStampsOriginModule(): void
    {
        $provider = new ListenerProvider();
        $guard = new StormGuard(new StormProtectionConfig());
        $inner = new EventDispatcher($provider, $provider, $guard);

        $moduleDispatcher = new ModuleEventDispatcher($inner, 'orders');

        $envelope = $this->createEnvelope();
        $result = $moduleDispatcher->dispatchEnvelope($envelope);

        self::assertSame('orders', $result->originModule);
    }

    #[Test]
    public function dispatchEnvelopeOverridesExistingOriginModule(): void
    {
        $provider = new ListenerProvider();
        $guard = new StormGuard(new StormProtectionConfig());
        $inner = new EventDispatcher($provider, $provider, $guard);

        $moduleDispatcher = new ModuleEventDispatcher($inner, 'orders');

        $envelope = $this->createEnvelope(originModule: 'payments');
        $result = $moduleDispatcher->dispatchEnvelope($envelope);

        // ModuleEventDispatcher always stamps — overrides existing originModule
        self::assertSame('orders', $result->originModule);
    }

    private function createEnvelope(?string $originModule = null): EventEnvelope
    {
        $metadata = new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );

        return EventEnvelope::wrap(
            eventType: 'test.event',
            schemaVersion: 1,
            payload: [],
            metadata: $metadata,
            originModule: $originModule,
        );
    }
}
