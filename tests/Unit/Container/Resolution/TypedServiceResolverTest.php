<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Resolution;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\Resolution\TypedServiceResolver;

#[CoversClass(TypedServiceResolver::class)]
final class TypedServiceResolverTest extends TestCase
{
    #[Test]
    public function resolvesClassImplementingExpectedInterface(): void
    {
        $container = new Container();
        $container->instance(SampleStoreInterface::class, new SampleStoreA());
        $container->bind(SampleStoreA::class, SampleStoreA::class);

        $service = TypedServiceResolver::resolve(
            $container,
            SampleStoreA::class,
            SampleStoreInterface::class,
            'sample.store',
        );

        self::assertInstanceOf(SampleStoreA::class, $service);
        self::assertInstanceOf(SampleStoreInterface::class, $service);
    }

    #[Test]
    public function resolvesInterfaceItself(): void
    {
        // The resolver must accept the case where the configured FQCN is
        // the interface itself, because the container can be configured
        // to bind the interface to a concrete implementation.
        $container = new Container();
        $container->instance(SampleStoreInterface::class, new SampleStoreA());

        $service = TypedServiceResolver::resolve(
            $container,
            SampleStoreInterface::class,
            SampleStoreInterface::class,
            'sample.store',
        );

        self::assertInstanceOf(SampleStoreInterface::class, $service);
    }

    #[Test]
    public function rejectsEmptyFqcn(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Empty service identifier.*sample\.store/s');

        TypedServiceResolver::resolve($container, '', SampleStoreInterface::class, 'sample.store');
    }

    #[Test]
    public function rejectsUnknownClass(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not name a known class or interface/s');

        TypedServiceResolver::resolve(
            $container,
            'NonExistent\\Class\\Name\\' . uniqid('Pulsar', true),
            SampleStoreInterface::class,
            'sample.store',
        );
    }

    #[Test]
    public function rejectsClassThatDoesNotImplementInterface(): void
    {
        // The configured FQCN names a real class but it has no relationship
        // to the expected interface. Refuse — this is the primary RCE
        // pattern: an attacker-controlled config value that points at any
        // class registered in the container would otherwise be instantiated.
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a subtype of/s');

        TypedServiceResolver::resolve(
            $container,
            UnrelatedService::class,
            SampleStoreInterface::class,
            'sample.store',
        );
    }

    #[Test]
    public function diagnosticMessageIncludesConfigKey(): void
    {
        $container = new Container();

        try {
            TypedServiceResolver::resolve(
                $container,
                UnrelatedService::class,
                SampleStoreInterface::class,
                'payments.idempotency.store',
            );
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('payments.idempotency.store', $e->getMessage());
        }
    }
}

interface SampleStoreInterface
{
    public function name(): string;
}

final class SampleStoreA implements SampleStoreInterface
{
    public function name(): string
    {
        return 'a';
    }
}

final class UnrelatedService
{
    public function noop(): void {}
}
