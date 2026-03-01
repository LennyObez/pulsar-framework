<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiled;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiled\CompiledContainer;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeManager;
use stdClass;

#[CoversClass(CompiledContainer::class)]
final class CompiledContainerTest extends TestCase
{
    #[Test]
    public function getResolvesViaMethodMap(): void
    {
        $container = new TestCompiledContainer();

        $result = $container->get('test.service');

        self::assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function getThrowsForUnknownService(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(NotFoundException::class);

        $_ = $container->get('nonexistent');
    }

    #[Test]
    public function hasTrueForMethodMap(): void
    {
        $container = new TestCompiledContainer();

        self::assertTrue($container->has('test.service'));
        self::assertFalse($container->has('nonexistent'));
    }

    #[Test]
    public function bindThrowsOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->bind('foo', stdClass::class);
    }

    #[Test]
    public function singletonThrowsOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->singleton('foo', stdClass::class);
    }

    #[Test]
    public function bindWithLifetimeThrowsOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->bindWithLifetime('foo', stdClass::class);
    }

    #[Test]
    public function tagThrowsOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->tag('foo', 'tag');
    }

    #[Test]
    public function decorateThrowsOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container');

        $container->decorate('foo', stdClass::class);
    }

    #[Test]
    public function instanceAllowedOnCompiledContainer(): void
    {
        $container = new TestCompiledContainer();
        $obj = new stdClass();

        $container->instance('my.instance', $obj);

        self::assertSame($obj, $container->get('my.instance'));
    }

    #[Test]
    public function getBindingsReturnsMethodMapKeys(): void
    {
        $container = new TestCompiledContainer();

        self::assertSame(['test.service'], $container->getBindings());
    }

    #[Test]
    public function singletonCachingWorks(): void
    {
        $container = new TestCompiledContainer();

        $first = $container->get('test.service');
        $second = $container->get('test.service');

        self::assertSame($first, $second);
    }

    #[Test]
    public function freezeBlocksInstanceRegistration(): void
    {
        $container = new TestCompiledContainer();

        // Before freeze, instance() works
        $container->instance('pre.freeze', new stdClass());
        self::assertNotNull($container->get('pre.freeze'));

        $container->freeze();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container after freeze');

        $container->instance('post.freeze', new stdClass());
    }

    #[Test]
    public function freezeBlocksForgetInstance(): void
    {
        $container = new TestCompiledContainer();
        $container->instance('svc', new stdClass());
        $container->freeze();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot modify a compiled container after freeze');

        $container->forgetInstance('svc');
    }

    #[Test]
    public function transientServicesNotCached(): void
    {
        $container = new TestCompiledContainerWithLifetimes();

        $first = $container->get('transient.service');
        $second = $container->get('transient.service');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function scopedServiceUseScopeManager(): void
    {
        $container = new TestCompiledContainerWithLifetimes();
        $scopeManager = new ScopeManager();
        $container->setScopeManager($scopeManager);

        $scopeManager->beginScope(Lifetime::RequestScope);

        $first = $container->get('request.service');
        $second = $container->get('request.service');

        self::assertSame($first, $second);

        $scopeManager->endScope(Lifetime::RequestScope);
    }
}

final class TestCompiledContainer extends CompiledContainer
{
    protected array $methodMap = [
        'test.service' => 'createTestService',
    ];

    protected function createTestService(): object
    {
        return new stdClass();
    }
}

final class TestCompiledContainerWithLifetimes extends CompiledContainer
{
    protected array $methodMap = [
        'singleton.service' => 'createSingletonService',
        'transient.service' => 'createTransientService',
        'request.service' => 'createRequestService',
    ];

    protected array $lifetimeMap = [
        'singleton.service' => Lifetime::Singleton,
        'transient.service' => Lifetime::Transient,
        'request.service' => Lifetime::RequestScope,
    ];

    protected function createSingletonService(): object
    {
        return new stdClass();
    }

    protected function createTransientService(): object
    {
        return new stdClass();
    }

    protected function createRequestService(): object
    {
        return new stdClass();
    }
}
