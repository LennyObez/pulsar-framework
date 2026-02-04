<?php

declare(strict_types=1);

namespace Pulsar\Benchmarks;

use Pulsar\Container\BindingType;
use Pulsar\Container\Container;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use stdClass;

/**
 * Benchmarks for the dependency injection container.
 *
 * @BeforeMethods("setUp")
 * @Revs(1000)
 * @Iterations(5)
 */
final class ContainerBench
{
    private Container $container;

    /**
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function setUp(): void
    {
        $this->container = new Container();

        // Register various bindings for benchmarking
        $this->container->instance('instance', new stdClass());

        $this->container->bind('singleton', fn() => new stdClass());

        $this->container->bind('factory', fn() => new stdClass(), BindingType::Factory);

        // Pre-resolve singleton to cache it
        $this->container->get('singleton');
    }

    /**
     * Benchmark: Resolving a pre-registered instance.
     *
     * This is the fastest path - direct array lookup.
     *
     * @Subject
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function benchInstanceResolution(): void
    {
        $this->container->get('instance');
    }

    /**
     * Benchmark: Resolving a cached singleton.
     *
     * After first resolution, singletons are cached.
     *
     * @Subject
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function benchSingletonCachedResolution(): void
    {
        $this->container->get('singleton');
    }

    /**
     * Benchmark: Resolving a factory binding.
     *
     * Factory bindings create new instances each time.
     *
     * @Subject
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function benchFactoryResolution(): void
    {
        $this->container->get('factory');
    }

    /**
     * Benchmark: Checking if a binding exists.
     *
     * @Subject
     */
    public function benchHasExisting(): void
    {
        $this->container->has('instance');
    }

    /**
     * Benchmark: Checking if a binding does not exist.
     *
     * @Subject
     */
    public function benchHasNonExisting(): void
    {
        $this->container->has('non-existing');
    }

    /**
     * Benchmark: Registering a new binding.
     *
     * Note: This modifies state, so results may vary.
     *
     * @Subject
     * @BeforeMethods("setUpFresh")
     */
    public function benchBindRegistration(): void
    {
        $this->container->bind('new-binding', fn() => new stdClass());
    }

    public function setUpFresh(): void
    {
        $this->container = new Container();
    }
}
