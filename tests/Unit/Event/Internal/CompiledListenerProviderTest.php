<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Pulsar\Event\EventSubscriberInterface;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\CompiledListenerProvider;
use RuntimeException;
use stdClass;

#[CoversClass(CompiledListenerProvider::class)]
final class CompiledListenerProviderTest extends TestCase
{
    // ---- getListenersForEvent ----

    #[Test]
    public function getListenersForEventReturnsEmptyForEmptyMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $listeners = $provider->getListenersForEvent(new stdClass());

        self::assertSame([], iterator_to_array($listeners));
    }

    #[Test]
    public function getListenersForEventResolvesListenerFromContainer(): void
    {
        $listener = new CLPTestListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturn($listener);

        $compiledMap = [
            stdClass::class => [
                'listeners' => [
                    ['class' => CLPTestListener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);
        $event = new stdClass();

        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
        }

        self::assertTrue($listener->called);
    }

    #[Test]
    public function getListenersForEventSortsByPriorityDescending(): void
    {
        $highListener = new CLPOrderTrackingListener();
        $lowListener = new CLPOrderTrackingListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static function (string $class) use ($highListener, $lowListener): object {
                return $class === 'HighListener' ? $highListener : $lowListener;
            });

        $compiledMap = [
            stdClass::class => [
                'listeners' => [
                    ['class' => 'LowListener', 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                    ['class' => 'HighListener', 'method' => 'handle', 'priority' => 100, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);
        $event = new stdClass();

        $order = [];
        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
            assert(is_array($callable));
            $order[] = $callable[0] === $highListener ? 'high' : 'low';
        }

        self::assertSame(['high', 'low'], $order);
    }

    #[Test]
    public function getListenersForEventCachesResults(): void
    {
        $callCount = 0;
        $listener = new CLPTestListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static function () use (&$callCount, $listener): object {
                $callCount++;
                return $listener;
            });

        $compiledMap = [
            stdClass::class => [
                'listeners' => [
                    ['class' => CLPTestListener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);
        $event = new stdClass();

        // First call
        iterator_to_array($provider->getListenersForEvent($event));
        // Second call should use cache (no additional container->get calls)
        iterator_to_array($provider->getListenersForEvent($event));

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function getListenersForEventReturnsEmptyForUnregisteredEventClass(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $compiledMap = [
            Exception::class => [
                'listeners' => [
                    ['class' => CLPTestListener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        // stdClass has no listeners registered
        $listeners = $provider->getListenersForEvent(new stdClass());
        self::assertSame([], iterator_to_array($listeners));
    }

    #[Test]
    public function getListenersForEventCachesEmptyResult(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        // First call caches empty
        $result1 = iterator_to_array($provider->getListenersForEvent(new stdClass()));
        // Second call uses cache
        $result2 = iterator_to_array($provider->getListenersForEvent(new stdClass()));

        self::assertSame([], $result1);
        self::assertSame([], $result2);
    }

    // ---- Polymorphic matching (class hierarchy) ----

    #[Test]
    public function getListenersForEventMatchesParentClassRegistrations(): void
    {
        $listener = new CLPTestListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($listener);

        $compiledMap = [
            Exception::class => [
                'listeners' => [
                    ['class' => CLPTestListener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        // RuntimeException extends Exception — should match
        $event = new RuntimeException('test');
        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
        }

        self::assertTrue($listener->called);
    }

    #[Test]
    public function getListenersForEventMergesExactAndParentListeners(): void
    {
        $parentListener = new CLPTestListener();
        $childListener = new CLPTestListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static function (string $class) use ($parentListener, $childListener): object {
                return $class === 'ParentListener' ? $parentListener : $childListener;
            });

        $compiledMap = [
            Exception::class => [
                'listeners' => [
                    ['class' => 'ParentListener', 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
            RuntimeException::class => [
                'listeners' => [
                    ['class' => 'ChildListener', 'method' => 'handle', 'priority' => 10, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        $event = new RuntimeException('test');
        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
        }

        // Both listeners should have been called (child at higher priority, then parent)
        self::assertTrue($childListener->called);
        self::assertTrue($parentListener->called);
    }

    // ---- addListener throws (read-only) ----

    #[Test]
    public function addListenerThrowsEventException(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $provider->addListener(stdClass::class, static function (): void {});
    }

    #[Test]
    public function addListenerThrowsWithPriorityAndModuleId(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $this->expectException(EventException::class);

        $provider->addListener(stdClass::class, static function (): void {}, 10, 'mod');
    }

    // ---- addSubscriber throws (read-only) ----

    #[Test]
    public function addSubscriberThrowsEventException(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $subscriber = $this->createStub(EventSubscriberInterface::class);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $provider->addSubscriber($subscriber);
    }

    #[Test]
    public function addSubscriberThrowsWithModuleId(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $subscriber = $this->createStub(EventSubscriberInterface::class);

        $this->expectException(EventException::class);

        $provider->addSubscriber($subscriber, 'my-module');
    }

    // ---- listenerModuleIdsFor ----

    #[Test]
    public function listenerModuleIdsForReturnsIdsFromCompiledMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['billing', 'shipping'],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertSame(['billing', 'shipping'], $provider->listenerModuleIdsFor(stdClass::class));
    }

    #[Test]
    public function listenerModuleIdsForReturnsEmptyForUnregisteredEvent(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        self::assertSame([], $provider->listenerModuleIdsFor(stdClass::class));
    }

    #[Test]
    public function listenerModuleIdsForMergesAcrossHierarchy(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['parent-mod'],
            ],
            RuntimeException::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['child-mod'],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        $moduleIds = $provider->listenerModuleIdsFor(RuntimeException::class);

        self::assertContains('parent-mod', $moduleIds);
        self::assertContains('child-mod', $moduleIds);
    }

    #[Test]
    public function listenerModuleIdsForDeduplicatesAcrossHierarchy(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['shared-mod'],
            ],
            RuntimeException::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['shared-mod'],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        $moduleIds = $provider->listenerModuleIdsFor(RuntimeException::class);

        self::assertCount(1, $moduleIds);
        self::assertSame('shared-mod', $moduleIds[0]);
    }

    // ---- stormOverrideFor ----

    #[Test]
    public function stormOverrideForReturnsOverrideFromMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => 128,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertSame(128, $provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function stormOverrideForReturnsNullWhenMapHasNull(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertNull($provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function stormOverrideForReturnsNullForUnregisteredEvent(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        self::assertNull($provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function stormOverrideForResolvesFromParentClassMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => 50,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        // RuntimeException extends Exception — should inherit storm override
        self::assertSame(50, $provider->stormOverrideFor(RuntimeException::class));
    }

    #[Test]
    public function stormOverrideForReturnsFirstNonNullInHierarchy(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            RuntimeException::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null, // child has no override
                'listenerModuleIds' => [],
            ],
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => 75, // parent has override
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertSame(75, $provider->stormOverrideFor(RuntimeException::class));
    }

    // ---- requiresEnvelopeFor ----

    #[Test]
    public function requiresEnvelopeForReturnsTrueWhenMapSaysTrue(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => true,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertTrue($provider->requiresEnvelopeFor(stdClass::class));
    }

    #[Test]
    public function requiresEnvelopeForReturnsFalseWhenMapSaysFalse(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertFalse($provider->requiresEnvelopeFor(stdClass::class));
    }

    #[Test]
    public function requiresEnvelopeForReturnsFalseForUnregisteredEvent(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        self::assertFalse($provider->requiresEnvelopeFor(stdClass::class));
    }

    #[Test]
    public function requiresEnvelopeForInheritsFromParentClass(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => true,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        // RuntimeException extends Exception — should inherit requiresEnvelope
        self::assertTrue($provider->requiresEnvelopeFor(RuntimeException::class));
    }

    // ---- compiledEventClasses ----

    #[Test]
    public function compiledEventClassesReturnsEmptyForEmptyMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        self::assertSame([], $provider->compiledEventClasses());
    }

    #[Test]
    public function compiledEventClassesReturnsAllKeysFromMap(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
            Exception::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        $classes = $provider->compiledEventClasses();

        self::assertCount(2, $classes);
        self::assertContains(stdClass::class, $classes);
        self::assertContains(Exception::class, $classes);
    }

    // ---- Multiple listeners from different compiled entries ----

    #[Test]
    public function getListenersForEventMergesAndSortsAcrossHierarchy(): void
    {
        $parentListener = new CLPOrderTrackingListener();
        $childListener = new CLPOrderTrackingListener();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static function (string $class) use ($parentListener, $childListener): object {
                return $class === 'ParentHandler' ? $parentListener : $childListener;
            });

        $compiledMap = [
            Exception::class => [
                'listeners' => [
                    ['class' => 'ParentHandler', 'method' => 'handle', 'priority' => 5, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
            RuntimeException::class => [
                'listeners' => [
                    ['class' => 'ChildHandler', 'method' => 'handle', 'priority' => 10, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        $event = new RuntimeException('test');
        $callOrder = [];

        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
            assert(is_array($callable));
            if ($callable[0] === $childListener) {
                $callOrder[] = 'child';
            } else {
                $callOrder[] = 'parent';
            }
        }

        // Child has higher priority (10), so it should be first
        self::assertSame(['child', 'parent'], $callOrder);
    }
}

// ---- Test helpers ----

/** @internal */
final class CLPTestListener
{
    public bool $called = false;

    public function handle(object $event): void
    {
        $this->called = true;
    }
}

/** @internal */
final class CLPOrderTrackingListener
{
    public bool $called = false;

    public function handle(object $event): void
    {
        $this->called = true;
    }
}
