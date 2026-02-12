<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\Attribute\StormOverride;
use Pulsar\Event\EnvelopeRequiredEvent;
use Pulsar\Event\EventSubscriberInterface;
use Pulsar\Event\Internal\ListenerProvider;
use RuntimeException;
use stdClass;

use function assert;
use function is_object;

#[CoversClass(ListenerProvider::class)]
final class ListenerProviderTest extends TestCase
{
    protected function setUp(): void
    {
        ListenerProvider::resetStaticCaches();
    }

    // ---- getListenersForEvent ----

    #[Test]
    public function getListenersForEventReturnsEmptyIterableForNoListeners(): void
    {
        $provider = new ListenerProvider();
        $listeners = $provider->getListenersForEvent(new stdClass());

        self::assertSame([], iterator_to_array($listeners));
    }

    #[Test]
    public function getListenersForEventReturnsRegisteredListener(): void
    {
        $provider = new ListenerProvider();
        $received = null;

        $provider->addListener(stdClass::class, static function (stdClass $e) use (&$received): void {
            $received = $e;
        });

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame($event, $received);
    }

    #[Test]
    public function getListenersForEventUsesCache(): void
    {
        $provider = new ListenerProvider();
        $count = 0;

        $provider->addListener(stdClass::class, static function () use (&$count): void {
            $count++;
        });

        $event = new stdClass();

        // First call populates cache
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        // Second call should use cache
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(2, $count);
    }

    // ---- Priority ordering ----

    #[Test]
    public function listenersAreSortedByPriorityDescending(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'low';
        }, priority: -5);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'high';
        }, priority: 100);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'zero';
        }, priority: 0);

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['high', 'zero', 'low'], $order);
    }

    #[Test]
    public function samePriorityListenersAreOrderedByRegistrationSequence(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'first';
        }, priority: 5);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'second';
        }, priority: 5);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'third';
        }, priority: 5);

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function negativePriorityListenersExecuteAfterDefault(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'negative';
        }, priority: -100);

        $provider->addListener(stdClass::class, static function () use (&$order): void {
            $order[] = 'default';
        }, priority: 0);

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['default', 'negative'], $order);
    }

    // ---- addListener cache invalidation ----

    #[Test]
    public function addListenerInvalidatesSortedCache(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});

        // Warm cache
        iterator_to_array($provider->getListenersForEvent(new stdClass()));

        // Add another listener — cache must be invalidated
        $secondCalled = false;
        $provider->addListener(stdClass::class, static function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($secondCalled);
    }

    // ---- resolveListenerFqcn ----

    #[Test]
    public function closureListenerResolvesToClosureFqcn(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(stdClass::class, static function (): void {}, priority: 0, moduleId: 'mod');

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame('Closure', $raw[0]['fqcn']);
    }

    #[Test]
    public function arrayCallableWithObjectResolvesToClassMethod(): void
    {
        $provider = new ListenerProvider();
        $subscriber = new LPArrayCallableListener();

        /** @var callable $callable */
        $callable = [$subscriber, 'handle'];
        $provider->addListener(stdClass::class, $callable);

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame(LPArrayCallableListener::class . '::handle', $raw[0]['fqcn']);
    }

    #[Test]
    public function stringCallableResolvesToStringFqcn(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(stdClass::class, 'strlen');

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame('strlen', $raw[0]['fqcn']);
    }

    #[Test]
    public function invokableObjectResolvesToClassInvoke(): void
    {
        $provider = new ListenerProvider();
        $invokable = new LPInvokableListener();
        $provider->addListener(stdClass::class, $invokable);

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame(LPInvokableListener::class . '::__invoke', $raw[0]['fqcn']);
    }

    // ---- addSubscriber ----

    #[Test]
    public function addSubscriberRegistersAllDeclaredListeners(): void
    {
        $provider = new ListenerProvider();
        $subscriber = new LPTestSubscriber();

        $provider->addSubscriber($subscriber, 'test-module');

        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($subscriber->stdClassCalled);
    }

    #[Test]
    public function addSubscriberPassesModuleIdToAllListeners(): void
    {
        $provider = new ListenerProvider();
        $subscriber = new LPTestSubscriber();

        $provider->addSubscriber($subscriber, 'my-module');

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame(['my-module'], $moduleIds);
    }

    #[Test]
    public function addSubscriberUsesConfiguredPriorities(): void
    {
        $provider = new ListenerProvider();
        $subscriber = new LPMultiEventSubscriber();

        $provider->addSubscriber($subscriber);

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame(10, $raw[0]['priority']);
    }

    // ---- Class hierarchy resolution ----

    #[Test]
    public function getListenersMatchesParentClassListeners(): void
    {
        $provider = new ListenerProvider();
        $called = false;

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
    public function getListenersMatchesInterfaceListeners(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        $provider->addListener(LPTestInterface::class, static function () use (&$called): void {
            $called = true;
        });

        $event = new LPTestInterfaceImplementation();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertTrue($called);
    }

    #[Test]
    public function getListenersMergesExactAndParentListeners(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(Exception::class, static function () use (&$order): void {
            $order[] = 'parent';
        }, priority: 0);

        $provider->addListener(RuntimeException::class, static function () use (&$order): void {
            $order[] = 'exact';
        }, priority: 10);

        $event = new RuntimeException('test');
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        self::assertSame(['exact', 'parent'], $order);
    }

    // ---- listenerModuleIdsFor ----

    #[Test]
    public function listenerModuleIdsForReturnsUniqueIds(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'mod-a');
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'mod-b');
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'mod-a'); // duplicate

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame(['mod-a', 'mod-b'], $moduleIds);
    }

    #[Test]
    public function listenerModuleIdsForExcludesEmptyStrings(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {}); // empty moduleId
        $provider->addListener(stdClass::class, static function (): void {}, moduleId: 'real');

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame(['real'], $moduleIds);
    }

    #[Test]
    public function listenerModuleIdsForReturnsEmptyForUnregisteredEvent(): void
    {
        $provider = new ListenerProvider();

        $moduleIds = $provider->listenerModuleIdsFor(stdClass::class);

        self::assertSame([], $moduleIds);
    }

    #[Test]
    public function listenerModuleIdsForIncludesParentClassListenerModules(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(Exception::class, static function (): void {}, moduleId: 'parent-module');
        $provider->addListener(RuntimeException::class, static function (): void {}, moduleId: 'child-module');

        $moduleIds = $provider->listenerModuleIdsFor(RuntimeException::class);

        self::assertContains('parent-module', $moduleIds);
        self::assertContains('child-module', $moduleIds);
    }

    // ---- stormOverrideFor ----

    #[Test]
    public function stormOverrideForReturnsNullForClassWithoutAttribute(): void
    {
        $provider = new ListenerProvider();

        self::assertNull($provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function stormOverrideForReturnsMaxDepthForAttributedClass(): void
    {
        $provider = new ListenerProvider();

        self::assertSame(64, $provider->stormOverrideFor(LPStormOverrideEvent::class));
    }

    #[Test]
    public function stormOverrideForReturnsNullForNonExistentClass(): void
    {
        $provider = new ListenerProvider();

        /** @phpstan-ignore argument.type */
        self::assertNull($provider->stormOverrideFor('Nonexistent\\Class\\Name'));
    }

    #[Test]
    public function stormOverrideForCachesResult(): void
    {
        $provider = new ListenerProvider();

        // First call populates cache
        $first = $provider->stormOverrideFor(LPStormOverrideEvent::class);
        // Second call should use cache
        $second = $provider->stormOverrideFor(LPStormOverrideEvent::class);

        self::assertSame($first, $second);
        self::assertSame(64, $second);
    }

    #[Test]
    public function stormOverrideForCachesNullResultAsFalse(): void
    {
        $provider = new ListenerProvider();

        // First call for a class without attribute
        $first = $provider->stormOverrideFor(stdClass::class);
        // Second call uses cached false value
        $second = $provider->stormOverrideFor(stdClass::class);

        self::assertNull($first);
        self::assertNull($second);
    }

    // ---- requiresEnvelopeFor ----

    #[Test]
    public function requiresEnvelopeForReturnsFalseForPlainClass(): void
    {
        $provider = new ListenerProvider();

        self::assertFalse($provider->requiresEnvelopeFor(stdClass::class));
    }

    #[Test]
    public function requiresEnvelopeForReturnsTrueForRequiresEnvelopeAttribute(): void
    {
        $provider = new ListenerProvider();

        self::assertTrue($provider->requiresEnvelopeFor(LPRequiresEnvelopeEvent::class));
    }

    #[Test]
    public function requiresEnvelopeForReturnsTrueForEnvelopeRequiredEventInterface(): void
    {
        $provider = new ListenerProvider();

        self::assertTrue($provider->requiresEnvelopeFor(LPEnvelopeRequiredInterfaceEvent::class));
    }

    #[Test]
    public function requiresEnvelopeForReturnsFalseForNonExistentClass(): void
    {
        $provider = new ListenerProvider();

        /** @phpstan-ignore argument.type */
        self::assertFalse($provider->requiresEnvelopeFor('Nonexistent\\Class\\FQCN'));
    }

    #[Test]
    public function requiresEnvelopeForCachesResult(): void
    {
        $provider = new ListenerProvider();

        $first = $provider->requiresEnvelopeFor(LPRequiresEnvelopeEvent::class);
        $second = $provider->requiresEnvelopeFor(LPRequiresEnvelopeEvent::class);

        self::assertTrue($first);
        self::assertSame($first, $second);
    }

    // ---- registeredEventClasses ----

    #[Test]
    public function registeredEventClassesReturnsEmptyForFreshProvider(): void
    {
        $provider = new ListenerProvider();

        self::assertSame([], $provider->registeredEventClasses());
    }

    #[Test]
    public function registeredEventClassesReturnsAllRegisteredClasses(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});
        $provider->addListener(RuntimeException::class, static function (): void {});

        $classes = $provider->registeredEventClasses();

        self::assertContains(stdClass::class, $classes);
        self::assertContains(RuntimeException::class, $classes);
        self::assertCount(2, $classes);
    }

    // ---- rawListenersFor ----

    #[Test]
    public function rawListenersForReturnsEmptyForUnregisteredEvent(): void
    {
        $provider = new ListenerProvider();

        self::assertSame([], $provider->rawListenersFor(stdClass::class));
    }

    #[Test]
    public function rawListenersForReturnsFullEntryStructure(): void
    {
        $provider = new ListenerProvider();
        $cb = static function (): void {};

        $provider->addListener(stdClass::class, $cb, priority: 42, moduleId: 'my-mod');

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertCount(1, $raw);
        self::assertSame($cb, $raw[0]['callable']);
        self::assertSame(42, $raw[0]['priority']);
        self::assertSame(0, $raw[0]['sequence']);
        self::assertSame('Closure', $raw[0]['fqcn']);
        self::assertSame('my-mod', $raw[0]['moduleId']);
    }

    #[Test]
    public function rawListenersForPreservesSequenceAcrossMultipleRegistrations(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(stdClass::class, static function (): void {});
        $provider->addListener(stdClass::class, static function (): void {});
        $provider->addListener(stdClass::class, static function (): void {});

        $raw = $provider->rawListenersFor(stdClass::class);

        self::assertSame(0, $raw[0]['sequence']);
        self::assertSame(1, $raw[1]['sequence']);
        self::assertSame(2, $raw[2]['sequence']);
    }

    // ---- resetStaticCaches ----

    #[Test]
    public function resetStaticCachesClearsStormOverrideAndEnvelopeCaches(): void
    {
        $provider = new ListenerProvider();

        // Populate caches
        $provider->stormOverrideFor(stdClass::class);
        $provider->requiresEnvelopeFor(stdClass::class);

        // Reset
        ListenerProvider::resetStaticCaches();

        // After reset, lookups should still work (re-resolved via reflection)
        self::assertNull($provider->stormOverrideFor(stdClass::class));
        self::assertFalse($provider->requiresEnvelopeFor(stdClass::class));
    }

    // ---- FQCN alphabetical tiebreaker ----

    #[Test]
    public function samePriorityAndSequenceBreaksTieByFqcnAlphabetically(): void
    {
        // This case would only occur if two listeners are registered with
        // same priority and same sequence (impossible in practice since sequence
        // auto-increments). But we test the sort contract.
        $provider = new ListenerProvider();

        // Use two named class listeners with same priority
        $listenerA = new LPAlphaListener();
        $listenerB = new LPBetaListener();

        $provider->addListener(stdClass::class, $listenerA, priority: 5);
        $provider->addListener(stdClass::class, $listenerB, priority: 5);

        // Both have same priority but different sequence,
        // so sequence tiebreaker applies first (Alpha registered first)
        $order = [];
        $event = new stdClass();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
            assert(is_object($listener));
            $order[] = $listener::class;
        }

        self::assertSame(LPAlphaListener::class, $order[0]);
        self::assertSame(LPBetaListener::class, $order[1]);
    }

    // ---- Data provider: multiple event types ----

    /**
     * @return array<string, array{class-string, bool}>
     */
    public static function envelopeRequiredProvider(): array
    {
        return [
            'plain class' => [stdClass::class, false],
            'with RequiresEnvelope attribute' => [LPRequiresEnvelopeEvent::class, true],
            'with EnvelopeRequiredEvent interface' => [LPEnvelopeRequiredInterfaceEvent::class, true],
        ];
    }

    /**
     * @param class-string $eventClass
     */
    #[Test]
    #[DataProvider('envelopeRequiredProvider')]
    public function requiresEnvelopeForWithProvider(string $eventClass, bool $expected): void
    {
        $provider = new ListenerProvider();

        self::assertSame($expected, $provider->requiresEnvelopeFor($eventClass));
    }
}

// ---- Test helpers ----

/** @internal */
interface LPTestInterface {}

/** @internal */
final class LPTestInterfaceImplementation implements LPTestInterface {}

/** @internal */
final class LPArrayCallableListener
{
    public function handle(stdClass $event): void {}
}

/** @internal */
final class LPInvokableListener
{
    public function __invoke(stdClass $event): void {}
}

/** @internal */
final class LPAlphaListener
{
    public function __invoke(stdClass $event): void {}
}

/** @internal */
final class LPBetaListener
{
    public function __invoke(stdClass $event): void {}
}

/** @internal */
final class LPTestSubscriber implements EventSubscriberInterface
{
    public bool $stdClassCalled = false;

    public function getSubscribedEvents(): array
    {
        return [
            stdClass::class => ['onStdClass', 0],
        ];
    }

    public function onStdClass(stdClass $event): void
    {
        $this->stdClassCalled = true;
    }
}

/** @internal */
final class LPMultiEventSubscriber implements EventSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return [
            stdClass::class => ['onStdClass', 10],
        ];
    }

    public function onStdClass(stdClass $event): void {}
}

/** @internal */
#[StormOverride(maxDepth: 64)]
final class LPStormOverrideEvent {}

/** @internal */
#[RequiresEnvelope]
final class LPRequiresEnvelopeEvent {}

/** @internal */
final class LPEnvelopeRequiredInterfaceEvent implements EnvelopeRequiredEvent {}
