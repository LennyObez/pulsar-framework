<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Lazy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\Lazy\LazyServiceFactory;

#[CoversClass(LazyServiceFactory::class)]
final class LazyServiceFactoryTest extends TestCase
{
    #[Test]
    public function deferredInstantiation(): void
    {
        $state = ['instantiated' => false];
        $container = new Container();

        $proxy = LazyServiceFactory::create(
            LazyTarget::class,
            static function () use (&$state): object {
                $state['instantiated'] = true;
                return new LazyTarget();
            },
            $container,
        );

        // Constructor not called yet
        self::assertFalse($state['instantiated']);

        // First method call triggers resolution
        /** @var LazyTarget $proxy */
        $proxy->getValue();

        self::assertTrue($state['instantiated']);
    }

    #[Test]
    public function sameInstanceOnMultipleCalls(): void
    {
        $callCount = 0;
        $container = new Container();

        $proxy = LazyServiceFactory::create(
            LazyTarget::class,
            static function () use (&$callCount): object {
                $callCount++;
                return new LazyTarget();
            },
            $container,
        );

        /** @var LazyTarget $proxy */
        $proxy->getValue();
        $proxy->getValue();

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function classStringBinding(): void
    {
        $container = new Container();
        $container->bind(LazyTarget::class, LazyTarget::class);

        $proxy = LazyServiceFactory::create(LazyTarget::class, LazyTarget::class, $container);

        self::assertInstanceOf(LazyTarget::class, $proxy);
        self::assertSame('lazy-value', $proxy->getValue());
    }
}

class LazyTarget
{
    public string $value = 'lazy-value';

    public function getValue(): string
    {
        return $this->value;
    }
}
