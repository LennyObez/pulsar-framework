<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiled;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiled\CompiledContainer;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Container\Scope\ScopeManager;
use stdClass;

use function assert;

#[CoversClass(CompiledContainer::class)]
final class CompiledContainerAdvancedTest extends TestCase
{
    #[Test]
    public function whenThrowsOnCompiledContainer(): void
    {
        $container = new AdvancedTestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->when('Consumer');
    }

    #[Test]
    public function addContextualBindingThrowsOnCompiledContainer(): void
    {
        $container = new AdvancedTestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        /** @var class-string $concrete */
        $concrete = trim('Concrete');
        $container->addContextualBinding('Consumer', 'Abstract', $concrete);
    }

    #[Test]
    public function processCompilerPassesThrowsOnCompiledContainer(): void
    {
        $container = new AdvancedTestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->processCompilerPasses(new \Pulsar\Container\Compiler\PassRunner());
    }

    #[Test]
    public function registerDeferredProviderThrowsOnCompiledContainer(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $provider = $this->createStub(DeferredServiceProviderInterface::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->registerDeferredProvider($provider);
    }

    #[Test]
    public function getDefinitionsReturnsEmptyArray(): void
    {
        $container = new AdvancedTestCompiledContainer();

        self::assertSame([], $container->getDefinitions());
    }

    #[Test]
    public function getTaggedServiceIdsReturnsEmptyByDefault(): void
    {
        $container = new AdvancedTestCompiledContainer();

        self::assertSame([], $container->getTaggedServiceIds('some_tag'));
    }

    #[Test]
    public function validateScopeGraphIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new AdvancedTestCompiledContainer();

        $container->validateScopeGraph();
    }

    #[Test]
    public function setResolutionHintsIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new AdvancedTestCompiledContainer();

        $container->setResolutionHints([]);
        $container->setResolutionHints(null);
    }

    #[Test]
    public function getInstancesReturnsRegisteredInstanceKeys(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $container->instance('svc1', new stdClass());
        $container->instance('svc2', new stdClass());

        $instances = $container->getInstances();

        self::assertContains('svc1', $instances);
        self::assertContains('svc2', $instances);
    }

    #[Test]
    public function hasTrueForInstanceRegistrations(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $container->instance('runtime.svc', new stdClass());

        self::assertTrue($container->has('runtime.svc'));
    }

    #[Test]
    public function forgetInstanceRemovesBeforeFreeze(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $container->instance('svc', new stdClass());

        self::assertTrue($container->has('svc'));

        $container->forgetInstance('svc');

        self::assertFalse($container->has('svc'));
    }

    #[Test]
    public function circularDependencyThrows(): void
    {
        $container = new CircularCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $_ = $container->get('a');
    }

    #[Test]
    public function beginAndEndRequestScope(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->beginRequestScope();
        self::assertTrue($scopeManager->isActive(Lifetime::RequestScope));

        $container->endRequestScope();
        self::assertFalse($scopeManager->isActive(Lifetime::RequestScope));
    }

    #[Test]
    public function beginAndEndTenantScope(): void
    {
        $container = new AdvancedTestCompiledContainer();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $container->beginTenantScope('tenant-1');
        self::assertTrue($scopeManager->isActive(Lifetime::TenantScope));
        self::assertSame('tenant-1', $scopeManager->currentTenantId());

        $container->endTenantScope();
        self::assertFalse($scopeManager->isActive(Lifetime::TenantScope));
    }

    #[Test]
    public function scopeMethodsNoOpWithoutScopeManager(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new AdvancedTestCompiledContainer();

        // Should not throw without scope manager
        $container->beginRequestScope();
        $container->endRequestScope();
        $container->beginTenantScope('t');
        $container->endTenantScope();
    }

    #[Test]
    public function tenantScopedServiceUseScopeManager(): void
    {
        $container = new TenantScopedCompiledContainer();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $scopeManager->beginScope(Lifetime::TenantScope, 'tenant-A');

        $first = $container->get('tenant.service');
        $second = $container->get('tenant.service');

        self::assertSame($first, $second);

        $scopeManager->endScope(Lifetime::TenantScope);
    }
}

final class AdvancedTestCompiledContainer extends CompiledContainer
{
    protected array $methodMap = [];
}

final class CircularCompiledContainer extends CompiledContainer
{
    protected array $methodMap = [
        'a' => 'createA',
    ];

    protected function createA(): object
    {
        $result = $this->get('a');
        assert($result instanceof stdClass);

        return $result;
    }
}

final class TenantScopedCompiledContainer extends CompiledContainer
{
    protected array $methodMap = [
        'tenant.service' => 'createTenantService',
    ];

    protected array $lifetimeMap = [
        'tenant.service' => Lifetime::TenantScope,
    ];

    protected function createTenantService(): object
    {
        return new stdClass();
    }
}
