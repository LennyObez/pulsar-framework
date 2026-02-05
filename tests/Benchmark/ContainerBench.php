<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Container\BindingType;
use Pulsar\Container\Container;
use stdClass;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class ContainerBench
{
    private Container $container;

    public function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance('instance', new stdClass());
        $this->container->bind('singleton', fn() => new stdClass());
        $this->container->bind('factory', fn() => new stdClass(), BindingType::Factory);
        $this->container->get('singleton');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchInstanceResolution(): void
    {
        $this->container->get('instance');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchSingletonCachedResolution(): void
    {
        $this->container->get('singleton');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchFactoryResolution(): void
    {
        $this->container->get('factory');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchHasExisting(): void
    {
        /** @phpstan-ignore method.resultUnused */
        $this->container->has('instance');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchHasNonExisting(): void
    {
        /** @phpstan-ignore method.resultUnused */
        $this->container->has('non-existing');
    }

    #[Subject]
    #[BeforeMethods('setUpFresh')]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchBindRegistration(): void
    {
        $this->container->bind('new-binding', fn() => new stdClass());
    }

    public function setUpFresh(): void
    {
        $this->container = new Container();
    }
}
