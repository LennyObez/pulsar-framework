<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ExtensionRegistry::class)]
#[CoversClass(ExtensionBootstrap::class)]
final class MultiExtensionBootTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ExtensionRegistry tests
    // -------------------------------------------------------------------------

    #[Test]
    public function registryTracksMultipleExtensions(): void
    {
        $registry = new ExtensionRegistry();
        $extA = new MultiExtDummy('ext/alpha');
        $extB = new MultiExtDummy('ext/beta');
        $manifest = $this->makeManifest('ext/alpha');
        $manifestB = $this->makeManifest('ext/beta');

        $registry->add($extA, $manifest);
        $registry->add($extB, $manifestB);

        self::assertSame(2, $registry->count());
        self::assertTrue($registry->has('ext/alpha'));
        self::assertTrue($registry->has('ext/beta'));
    }

    #[Test]
    public function registryThrowsOnDuplicateRegistration(): void
    {
        $registry = new ExtensionRegistry();
        $ext = new MultiExtDummy('ext/dup');
        $manifest = $this->makeManifest('ext/dup');

        $registry->add($ext, $manifest);

        $this->expectException(ExtensionException::class);
        $registry->add($ext, $manifest); // second add must throw
    }

    #[Test]
    public function registryGetThrowsForUnknownExtension(): void
    {
        $registry = new ExtensionRegistry();

        $this->expectException(ExtensionException::class);
        (void) $registry->get('ext/unknown');
    }

    #[Test]
    public function registryStateTransitionsFollowLifecycle(): void
    {
        $registry = new ExtensionRegistry();
        $ext = new MultiExtDummy('ext/lifecycle');
        $registry->add($ext, $this->makeManifest('ext/lifecycle'));

        // Initial state set by add() is Discovered
        self::assertSame(ExtensionLifecycle::Discovered, $registry->getState('ext/lifecycle'));

        $registry->setState('ext/lifecycle', ExtensionLifecycle::Registered);
        self::assertSame(ExtensionLifecycle::Registered, $registry->getState('ext/lifecycle'));

        $registry->setState('ext/lifecycle', ExtensionLifecycle::Booted);
        self::assertSame(ExtensionLifecycle::Booted, $registry->getState('ext/lifecycle'));
    }

    #[Test]
    public function registryAllBootedReturnsTrueWhenAllBooted(): void
    {
        $registry = new ExtensionRegistry();
        $registry->add(new MultiExtDummy('ext/a'), $this->makeManifest('ext/a'));
        $registry->add(new MultiExtDummy('ext/b'), $this->makeManifest('ext/b'));

        $registry->setState('ext/a', ExtensionLifecycle::Booted);
        $registry->setState('ext/b', ExtensionLifecycle::Booted);

        self::assertTrue($registry->allBooted());
    }

    #[Test]
    public function registryAllBootedReturnsFalseWhenAnyNotBooted(): void
    {
        $registry = new ExtensionRegistry();
        $registry->add(new MultiExtDummy('ext/a'), $this->makeManifest('ext/a'));
        $registry->add(new MultiExtDummy('ext/b'), $this->makeManifest('ext/b'));

        $registry->setState('ext/a', ExtensionLifecycle::Booted);
        // ext/b stays Discovered

        self::assertFalse($registry->allBooted());
    }

    #[Test]
    public function registryHasFailedDetectsFailedExtensions(): void
    {
        $registry = new ExtensionRegistry();
        $registry->add(new MultiExtDummy('ext/broken'), $this->makeManifest('ext/broken'));

        self::assertFalse($registry->hasFailed());

        $registry->setState('ext/broken', ExtensionLifecycle::Failed);

        self::assertTrue($registry->hasFailed());
        self::assertArrayHasKey('ext/broken', $registry->failed());
    }

    #[Test]
    public function registryInStateFiltersCorrectly(): void
    {
        $registry = new ExtensionRegistry();
        $registry->add(new MultiExtDummy('ext/a'), $this->makeManifest('ext/a'));
        $registry->add(new MultiExtDummy('ext/b'), $this->makeManifest('ext/b'));

        $registry->setState('ext/a', ExtensionLifecycle::Booted);
        // ext/b stays Discovered

        $booted = $registry->inState(ExtensionLifecycle::Booted);
        $discovered = $registry->inState(ExtensionLifecycle::Discovered);

        self::assertCount(1, $booted);
        self::assertArrayHasKey('ext/a', $booted);
        self::assertCount(1, $discovered);
        self::assertArrayHasKey('ext/b', $discovered);
    }

    // -------------------------------------------------------------------------
    // ExtensionBootstrap tests
    // -------------------------------------------------------------------------

    #[Test]
    public function bootstrapRegisterCallsRegisterOnAllExtensions(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(new ServiceRegisteringExtension('ext/reg-a', $order), $this->makeManifest('ext/reg-a'));
        $bootstrap->addExtension(new ServiceRegisteringExtension('ext/reg-b', $order), $this->makeManifest('ext/reg-b'));

        $container = new Container();
        $bootstrap->register($container);

        self::assertContains('ext/reg-a', (array) $order);
        self::assertContains('ext/reg-b', (array) $order);
    }

    #[Test]
    public function bootstrapRegisterIsIdempotent(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(new ServiceRegisteringExtension('ext/idempotent', $order), $this->makeManifest('ext/idempotent'));

        $container = new Container();
        $bootstrap->register($container);
        $bootstrap->register($container); // second call is a no-op

        self::assertCount(1, $order); // registered only once
    }

    #[Test]
    public function bootstrapBootThrowsIfNotRegisteredFirst(): void
    {
        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(new MultiExtDummy('ext/skip'), $this->makeManifest('ext/skip'));

        $container = new Container();
        $router = new Router();

        $this->expectException(ExtensionException::class);
        $bootstrap->boot($container, $router);
    }

    #[Test]
    public function bootstrapFullLifecycleRegisterThenBoot(): void
    {
        /** @var ArrayObject<int, string> $lifecycle */
        $lifecycle = new ArrayObject();

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(
            new LifecycleTrackingExtension('ext/full', $lifecycle),
            $this->makeManifest('ext/full'),
        );

        $container = new Container();
        $router = new Router();

        $bootstrap->register($container);
        $bootstrap->boot($container, $router);

        self::assertContains('register:ext/full', (array) $lifecycle);
        self::assertContains('boot:ext/full', (array) $lifecycle);
        self::assertSame(
            ExtensionLifecycle::Booted,
            $bootstrap->registry->getState('ext/full'),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeManifest(string $name): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'extension_class' => MultiExtDummy::class,
        ]);
    }
}

/** @internal */
final class MultiExtDummy implements ExtensionInterface
{
    public function __construct(private readonly string $extName) {}

    public function name(): string
    {
        return $this->extName;
    }

    public function register(ContainerInterface $container): void {}

    public function boot(ContainerInterface $container, RouterInterface $router): void {}

    public function providers(): array
    {
        return [];
    }
}

/** @internal */
final class ServiceRegisteringExtension implements ExtensionInterface
{
    /** @param ArrayObject<int, string> $order */
    public function __construct(
        private readonly string $extName,
        private readonly ArrayObject $order,
    ) {}

    public function name(): string
    {
        return $this->extName;
    }

    public function register(ContainerInterface $container): void
    {
        $this->order->append($this->extName);
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void {}

    public function providers(): array
    {
        return [];
    }
}

/** @internal */
final class LifecycleTrackingExtension implements ExtensionInterface
{
    /** @param ArrayObject<int, string> $lifecycle */
    public function __construct(
        private readonly string $extName,
        private readonly ArrayObject $lifecycle,
    ) {}

    public function name(): string
    {
        return $this->extName;
    }

    public function register(ContainerInterface $container): void
    {
        $this->lifecycle->append('register:' . $this->extName);
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->lifecycle->append('boot:' . $this->extName);
    }

    public function providers(): array
    {
        return [];
    }
}
