<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\CompiledListenerProvider;
use RuntimeException;
use stdClass;

#[CoversClass(CompiledListenerProvider::class)]
final class CompiledListenerProviderTest extends TestCase
{
    #[Test]
    public function test_getListenersForEvent_returns_empty_for_unregistered_event(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $listeners = $provider->getListenersForEvent(new stdClass());

        self::assertSame([], iterator_to_array($listeners));
    }

    #[Test]
    public function test_getListenersForEvent_resolves_from_container(): void
    {
        $listener = new class {
            public bool $called = false;

            public function handle(stdClass $event): void
            {
                $this->called = true;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with($listener::class)
            ->willReturn($listener);

        $compiledMap = [
            stdClass::class => [
                'listeners' => [
                    ['class' => $listener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
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
    public function test_addListener_throws(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $provider->addListener(stdClass::class, static function (): void {});
    }

    #[Test]
    public function test_addSubscriber_throws(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        $subscriber = new class implements \Pulsar\Event\EventSubscriberInterface {
            public function getSubscribedEvents(): array
            {
                return [];
            }
        };

        $this->expectException(EventException::class);

        $provider->addSubscriber($subscriber);
    }

    #[Test]
    public function test_listenerModuleIdsFor(): void
    {
        $container = $this->createMock(ContainerInterface::class);
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
    public function test_listenerModuleIdsFor_unknown_event(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new CompiledListenerProvider([], $container);

        self::assertSame([], $provider->listenerModuleIdsFor(stdClass::class));
    }

    #[Test]
    public function test_stormOverrideFor(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => 64,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertSame(64, $provider->stormOverrideFor(stdClass::class));
    }

    #[Test]
    public function test_requiresEnvelopeFor(): void
    {
        $container = $this->createMock(ContainerInterface::class);
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
        self::assertFalse($provider->requiresEnvelopeFor(Exception::class));
    }

    #[Test]
    public function test_compiledEventClasses(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $compiledMap = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        self::assertSame([stdClass::class], $provider->compiledEventClasses());
    }

    #[Test]
    public function test_polymorphic_matching_with_parent_class(): void
    {
        $listener = new class {
            public bool $called = false;

            public function handle(Exception $event): void
            {
                $this->called = true;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with($listener::class)
            ->willReturn($listener);

        $compiledMap = [
            Exception::class => [
                'listeners' => [
                    ['class' => $listener::class, 'method' => 'handle', 'priority' => 0, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $provider = new CompiledListenerProvider($compiledMap, $container);

        // Dispatch RuntimeException — should match parent Exception listeners
        $event = new RuntimeException('test');
        foreach ($provider->getListenersForEvent($event) as $callable) {
            $callable($event);
        }

        self::assertTrue($listener->called);
    }
}
