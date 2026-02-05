<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\Attribute\StormOverride;
use Pulsar\Event\EnvelopeRequiredEvent;
use Pulsar\Event\EventSubscriberInterface;
use Pulsar\Event\Internal\ListenerProvider;
use RuntimeException;
use stdClass;

#[CoversClass(ListenerProvider::class)]
final class ListenerProviderTest extends TestCase
{
    protected function setUp(): void
    {
        ListenerProvider::resetStaticCaches();
    }

    #[Test]
    public function test_getListenersForEvent_returns_empty_for_no_listeners(): void
    {
        $provider = new ListenerProvider();
        $event = new stdClass();

        $listeners = $provider->getListenersForEvent($event);

        self::assertSame([], iterator_to_array($listeners));
    }

    #[Test]
    public function test_addListener_and_getListenersForEvent(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        $provider->addListener(stdClass::class, static function (stdClass $e) use (&$called): void {
            $called = true;
        });

        $event = new stdClass();
        $listeners = $provider->getListenersForEvent($event);

        foreach ($listeners as $listener) {
            $listener($event);
        }

        self::assertTrue($called);
    }

    #[Test]
    public function test_priority_ordering_higher_first(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'low';
        }, priority: 0);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'high';
        }, priority: 10);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'medium';
        }, priority: 5);

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['high', 'medium', 'low'], $order);
    }

    #[Test]
    public function test_same_priority_preserves_registration_order(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'first';
        }, priority: 0);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 0);

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function test_addSubscriber_registers_all_events(): void
    {
        $provider = new ListenerProvider();

        $subscriber = new TestEventSubscriber();
        $provider->addSubscriber($subscriber, 'test-module');

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($subscriber->called);
    }

    #[Test]
    public function test_listenerModuleIdsFor_returns_unique_module_ids(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'module-a');
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'module-b');
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'module-a');

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame(['module-a', 'module-b'], $moduleIds);
    }

    #[Test]
    public function test_listenerModuleIdsFor_excludes_empty_moduleIds(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'module-a');

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame(['module-a'], $moduleIds);
    }

    #[Test]
    public function test_registeredEventClasses(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});

        $classes = $provider->registeredEventClasses();

        self::assertContains(stdClass::class, $classes);
    }

    #[Test]
    public function test_rawListenersFor(): void
    {
        $provider = new ListenerProvider();
        $cb = static function (): void {};

        $provider->addListener(stdClass::class, $cb, priority: 5, moduleId: 'mod');

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertCount(1, $raw);
        self::assertSame(5, $raw[0]['priority']);
        self::assertSame('mod', $raw[0]['moduleId']);
    }

    #[Test]
    public function test_resolves_listeners_for_parent_class(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        // Register listener for parent class
        $provider->addListener(Exception::class, static function () use (&$called): void {
            $called = true;
        });

        $event = new RuntimeException('test');
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($called);
    }

    #[Test]
    public function test_cache_invalidated_on_addListener(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});

        // Warm cache
        $event = new stdClass();
        iterator_to_array($provider->getListenersForEvent($event));

        // Add another listener
        $secondCalled = false;
        $provider->addListener(stdClass::class, static function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        // Verify cache invalidated — second listener should execute
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($secondCalled);
    }

    #[Test]
    public function test_stormOverrideFor_returns_null_for_no_attribute(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(stdClass::class, static function (): void {});

        self::assertNull($provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function test_stormOverrideFor_returns_maxDepth_for_attributed_class(): void
    {
        $provider = new ListenerProvider();

        self::assertSame(64, $provider->stormOverrideFor(StormOverrideTestEvent::class));
    }

    #[Test]
    public function test_requiresEnvelopeFor_returns_false_for_plain_class(): void
    {
        $provider = new ListenerProvider();

        self::assertFalse($provider->requiresEnvelopeFor(stdClass::class));
    }

    #[Test]
    public function test_requiresEnvelopeFor_returns_true_for_attributed_class(): void
    {
        $provider = new ListenerProvider();

        self::assertTrue($provider->requiresEnvelopeFor(RequiresEnvelopeTestEvent::class));
    }

    #[Test]
    public function test_requiresEnvelopeFor_returns_true_for_interface_class(): void
    {
        $provider = new ListenerProvider();

        self::assertTrue($provider->requiresEnvelopeFor(EnvelopeRequiredInterfaceTestEvent::class));
    }
}

/**
 * @internal Test helper
 */
final class TestEventSubscriber implements EventSubscriberInterface
{
    public bool $called = false;

    public function getSubscribedEvents(): array
    {
        return [
            stdClass::class => ['handleEvent', 0],
        ];
    }

    public function handleEvent(stdClass $event): void
    {
        $this->called = true;
    }
}

/**
 * @internal Test helper — event with StormOverride attribute
 */
#[StormOverride(maxDepth: 64)]
final class StormOverrideTestEvent {}

/**
 * @internal Test helper — event with RequiresEnvelope attribute
 */
#[RequiresEnvelope]
final class RequiresEnvelopeTestEvent {}

/**
 * @internal Test helper — event implementing EnvelopeRequiredEvent interface
 */
final class EnvelopeRequiredInterfaceTestEvent implements EnvelopeRequiredEvent {}
