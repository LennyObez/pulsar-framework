<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Provider\DeferredProviderRegistry;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Container\Scope\ScopeManager;
use stdClass;

use function assert;

#[CoversClass(Container::class)]
#[CoversClass(ContainerException::class)]
#[CoversClass(NotFoundException::class)]
final class ContainerAdvancedTest extends TestCase
{
    #[Test]
    public function bindWithLifetimeCreatesDefinitionWithCorrectLifetime(): void
    {
        $container = new Container();
        $container->bindWithLifetime('svc', fn() => new stdClass(), Lifetime::Transient);

        $definitions = $container->getDefinitions();

        self::assertArrayHasKey('svc', $definitions);
        self::assertSame(Lifetime::Transient, $definitions['svc']->lifetime);
    }

    #[Test]
    public function bindWithLifetimeTransientCreatesNewInstanceEachTime(): void
    {
        $container = new Container();
        $container->bindWithLifetime('svc', fn() => new stdClass(), Lifetime::Transient);

        $first = $container->get('svc');
        $second = $container->get('svc');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function bindWithLifetimeClearsCachedInstanceOnRebind(): void
    {
        $container = new Container();
        $container->bindWithLifetime('svc', fn() => new stdClass(), Lifetime::Singleton);

        $first = $container->get('svc');

        $container->bindWithLifetime('svc', fn() => new stdClass(), Lifetime::Singleton);
        $second = $container->get('svc');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function tagOnUnregisteredServiceThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot tag unregistered service');

        $container->tag('nonexistent', 'my_tag');
    }

    #[Test]
    public function tagAddsTagToDefinition(): void
    {
        $container = new Container();
        $container->bind('svc', fn() => new stdClass());
        $container->tag('svc', 'my_tag', 10);

        $definitions = $container->getDefinitions();
        self::assertCount(1, $definitions['svc']->tags);
        self::assertSame('my_tag', $definitions['svc']->tags[0]->name);
    }

    #[Test]
    public function getTaggedServiceIdsReturnsMatchingIds(): void
    {
        $container = new Container();
        $container->bind('svc1', fn() => new stdClass());
        $container->bind('svc2', fn() => new stdClass());
        $container->bind('svc3', fn() => new stdClass());

        $container->tag('svc1', 'tagged', 10);
        $container->tag('svc2', 'tagged', 5);
        // svc3 not tagged

        $taggedIds = $container->getTaggedServiceIds('tagged');

        self::assertContains('svc1', $taggedIds);
        self::assertContains('svc2', $taggedIds);
        self::assertNotContains('svc3', $taggedIds);
    }

    #[Test]
    public function decorateOnUnregisteredServiceThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot decorate unregistered service');

        $container->decorate('nonexistent', fn($inner, $c) => $inner);
    }

    #[Test]
    public function decorateAppliesDecoratorOnResolution(): void
    {
        $container = new Container();
        $container->bind('svc', fn() => new stdClass());

        $container->decorate('svc', fn(object $inner, ContainerInterface $c): object => (object) ['decorated' => true, 'inner' => $inner]);

        $result = $container->get('svc');
        assert($result instanceof stdClass);

        self::assertTrue($result->decorated);
    }

    #[Test]
    public function decorateClearsCachedInstance(): void
    {
        $container = new Container();
        $container->bind('svc', fn() => new stdClass());

        $first = $container->get('svc');

        $container->decorate('svc', fn(object $inner, ContainerInterface $c): object => (object) ['decorated' => true]);

        $second = $container->get('svc');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function deferredProviderResolvedViaGet(): void
    {
        $container = new Container();
        $registry = new DeferredProviderRegistry();

        $provider = new class implements DeferredServiceProviderInterface {
            public function register(ContainerInterface $container): void
            {
                $container->instance('lazy.svc', new stdClass());
            }

            public function provides(): array
            {
                return ['lazy.svc'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $container->setDeferredProviderRegistry($registry);

        $result = $container->get('lazy.svc');

        self::assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function deferredProviderResolvesViaDefinitionNotInstance(): void
    {
        $container = new Container();
        $registry = new DeferredProviderRegistry();

        $provider = new class implements DeferredServiceProviderInterface {
            public function register(ContainerInterface $container): void
            {
                $container->bind('lazy.def', fn() => new stdClass());
            }

            public function provides(): array
            {
                return ['lazy.def'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $container->setDeferredProviderRegistry($registry);

        $result = $container->get('lazy.def');

        self::assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function deferredProviderThrowsIfNothingRegistered(): void
    {
        $container = new Container();
        $registry = new DeferredProviderRegistry();

        $provider = new class implements DeferredServiceProviderInterface {
            public function register(ContainerInterface $container): void
            {
                // Deliberately does NOT register the service
            }

            public function provides(): array
            {
                return ['phantom.svc'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $container->setDeferredProviderRegistry($registry);

        $this->expectException(NotFoundException::class);

        $_ = $container->get('phantom.svc');
    }

    #[Test]
    public function registerDeferredProviderCreatesRegistryIfNull(): void
    {
        $container = new Container();

        $provider = new class implements DeferredServiceProviderInterface {
            public function register(ContainerInterface $container): void
            {
                $container->instance('auto.svc', new stdClass());
            }

            public function provides(): array
            {
                return ['auto.svc'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $container->registerDeferredProvider($provider);

        self::assertTrue($container->has('auto.svc'));

        $result = $container->get('auto.svc');
        self::assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function scopedInstancesAreCachedPerRequestScope(): void
    {
        $container = new Container();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->bindWithLifetime('req.svc', fn() => new stdClass(), Lifetime::RequestScope);

        $scopeManager->beginScope(Lifetime::RequestScope);

        $first = $container->get('req.svc');
        $second = $container->get('req.svc');

        self::assertSame($first, $second);

        $scopeManager->endScope(Lifetime::RequestScope);
    }

    #[Test]
    public function scopedInstancesAreEvictedOnScopeEnd(): void
    {
        $container = new Container();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->bindWithLifetime('req.svc', fn() => new stdClass(), Lifetime::RequestScope);

        $scopeManager->beginScope(Lifetime::RequestScope);
        $first = $container->get('req.svc');
        $scopeManager->endScope(Lifetime::RequestScope);

        $scopeManager->beginScope(Lifetime::RequestScope);
        $second = $container->get('req.svc');
        $scopeManager->endScope(Lifetime::RequestScope);

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function tenantScopedInstancesAreCachedPerTenant(): void
    {
        $container = new Container();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->bindWithLifetime('tenant.svc', fn() => new stdClass(), Lifetime::TenantScope);

        $scopeManager->beginScope(Lifetime::TenantScope, 'tenant-A');

        $first = $container->get('tenant.svc');
        $second = $container->get('tenant.svc');

        self::assertSame($first, $second);

        $scopeManager->endScope(Lifetime::TenantScope);
    }

    #[Test]
    public function beginAndEndRequestScopeDelegatesToScopeManager(): void
    {
        $container = new Container();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->beginRequestScope();
        self::assertTrue($scopeManager->isActive(Lifetime::RequestScope));

        $container->endRequestScope();
        self::assertFalse($scopeManager->isActive(Lifetime::RequestScope));
    }

    #[Test]
    public function beginAndEndTenantScopeDelegatesToScopeManager(): void
    {
        $container = new Container();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->beginTenantScope('tenant-X');
        self::assertTrue($scopeManager->isActive(Lifetime::TenantScope));
        self::assertSame('tenant-X', $scopeManager->currentTenantId());

        $container->endTenantScope();
        self::assertFalse($scopeManager->isActive(Lifetime::TenantScope));
    }

    #[Test]
    public function scopeMethodsAreNoOpWithoutScopeManager(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new Container();

        // Should not throw even without scope manager
        $container->beginRequestScope();
        $container->endRequestScope();
        $container->beginTenantScope('tenant-Y');
        $container->endTenantScope();
    }

    #[Test]
    public function processCompilerPassesRunsPassesOnDefinitions(): void
    {
        $container = new Container();
        $container->bind('svc', fn() => new stdClass());

        $runner = new PassRunner();
        $container->processCompilerPasses($runner);

        // After processing, definitions should still exist
        self::assertTrue($container->has('svc'));
    }

    #[Test]
    public function validateScopeGraphRunsWithoutError(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new Container();
        $container->bind('svc', fn() => new stdClass());

        // Should not throw for valid scope graph
        $container->validateScopeGraph();
    }

    #[Test]
    public function getDefinitionsReturnsAllDefinitions(): void
    {
        $container = new Container();
        $container->bind('a', fn() => new stdClass());
        $container->bind('b', fn() => new stdClass());

        $definitions = $container->getDefinitions();

        self::assertCount(2, $definitions);
        self::assertArrayHasKey('a', $definitions);
        self::assertArrayHasKey('b', $definitions);
    }

    #[Test]
    public function contextualBindingResolvesFromContainerWhenBound(): void
    {
        $container = new Container();
        $special = new AdvContextualSpecialImpl();

        $container->bind(AdvContextualConsumer::class, AdvContextualConsumer::class);
        $container->instance(AdvContextualSpecialImpl::class, $special);

        // Contextual binding gives a class-string that's registered as an instance
        $container->addContextualBinding(
            AdvContextualConsumer::class,
            AdvContextualAbstract::class,
            AdvContextualSpecialImpl::class,
        );

        /** @var AdvContextualConsumer $consumer */
        $consumer = $container->get(AdvContextualConsumer::class);

        self::assertSame($special, $consumer->dep);
    }

    #[Test]
    public function builtinTypeParameterWithDefaultUsesDefault(): void
    {
        $container = new Container();
        $container->bind(BuiltinWithDefaultStub::class, BuiltinWithDefaultStub::class);

        $result = $container->get(BuiltinWithDefaultStub::class);

        self::assertInstanceOf(BuiltinWithDefaultStub::class, $result);
        self::assertSame(42, $result->count);
    }

    #[Test]
    public function untypedParameterWithDefaultUsesDefault(): void
    {
        $container = new Container();
        $container->bind(UntypedWithDefaultStub::class, UntypedWithDefaultStub::class);

        $result = $container->get(UntypedWithDefaultStub::class);

        self::assertInstanceOf(UntypedWithDefaultStub::class, $result);
        self::assertSame('fallback', $result->value);
    }

    #[Test]
    public function bindWithStringConcreteNormalizesToClassString(): void
    {
        $container = new Container();
        $container->bind(stdClass::class, stdClass::class);

        $definitions = $container->getDefinitions();
        self::assertIsString($definitions[stdClass::class]->concrete);
    }

    #[Test]
    public function bindWithCallableNormalizesToClosure(): void
    {
        $container = new Container();
        $container->bind('svc', fn() => new stdClass());

        $definitions = $container->getDefinitions();
        self::assertInstanceOf(Closure::class, $definitions['svc']->concrete);
    }
}

class AdvContextualAbstract {}

class AdvContextualSpecialImpl extends AdvContextualAbstract {}

class AdvContextualConsumer
{
    public function __construct(
        public readonly AdvContextualAbstract $dep,
    ) {}
}

class BuiltinWithDefaultStub
{
    public function __construct(
        public readonly int $count = 42,
    ) {}
}

class UntypedWithDefaultStub
{
    /** @var mixed */
    public $value;

    /** @param mixed $value */
    public function __construct($value = 'fallback')
    {
        $this->value = $value;
    }
}
