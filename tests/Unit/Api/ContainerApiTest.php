<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;

#[CoversClass(Api::class)]
final class ContainerApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function containerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ContainerInterface::class);
    }

    #[Test]
    public function containerInterfaceHasBindMethod(): void
    {
        self::assertMethodSignature(
            ContainerInterface::class,
            'bind',
            ['string', 'callable|string', BindingType::class],
            'void',
        );
    }

    #[Test]
    public function containerInterfaceHasInstanceMethod(): void
    {
        self::assertMethodSignature(
            ContainerInterface::class,
            'instance',
            ['string', 'object'],
            'void',
        );
    }

    #[Test]
    public function containerInterfaceHasHasMethod(): void
    {
        self::assertMethodSignature(
            ContainerInterface::class,
            'has',
            ['string'],
            'bool',
        );
    }

    #[Test]
    public function containerInterfaceHasGetMethod(): void
    {
        self::assertMethodSignature(
            ContainerInterface::class,
            'get',
            ['string'],
            'mixed',
        );
    }

    #[Test]
    public function bindingTypeIsPublicApi(): void
    {
        self::assertHasApiAttribute(BindingType::class);
    }

    #[Test]
    public function bindingTypeHasExpectedCases(): void
    {
        self::assertEnumCases(BindingType::class, ['Singleton', 'Factory']);
    }

    #[Test]
    public function containerExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(ContainerException::class);
    }

    #[Test]
    public function containerExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(ContainerException::class, 'unresolvable');
        self::assertStaticFactoryExists(ContainerException::class, 'circularDependency');
    }

    #[Test]
    public function notFoundExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(NotFoundException::class);
    }
}
