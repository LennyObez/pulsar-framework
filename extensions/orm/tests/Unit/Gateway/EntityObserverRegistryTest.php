<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Gateway;

use ArrayObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Contracts\EntityObserverInterface;
use Pulsar\Extension\Orm\Domain\EntityEvent;
use Pulsar\Extension\Orm\Gateway\EntityObserverRegistry;
use stdClass;

final class EntityObserverRegistryTest extends TestCase
{
    #[Test]
    public function dispatchCallsRegisteredObserver(): void
    {
        $registry = new EntityObserverRegistry();

        $observer = new class implements EntityObserverInterface {
            public bool $wasCalled = false;

            public function handle(object $entity, EntityEvent $event): bool
            {
                $this->wasCalled = true;

                return true;
            }
        };

        $registry->register(stdClass::class, $observer);
        $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Created);

        self::assertTrue($observer->wasCalled);
    }

    #[Test]
    public function dispatchReturnsTrueWhenNoObservers(): void
    {
        $registry = new EntityObserverRegistry();

        $result = $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Creating);

        self::assertTrue($result);
    }

    #[Test]
    public function preEventReturningFalseVetoesOperation(): void
    {
        $registry = new EntityObserverRegistry();

        $observer = new class implements EntityObserverInterface {
            public function handle(object $entity, EntityEvent $event): bool
            {
                return false;
            }
        };

        $registry->register(stdClass::class, $observer);

        $result = $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Creating);

        self::assertFalse($result);
    }

    #[Test]
    public function postEventReturningFalseDoesNotVeto(): void
    {
        $registry = new EntityObserverRegistry();

        $observer = new class implements EntityObserverInterface {
            public function handle(object $entity, EntityEvent $event): bool
            {
                return false;
            }
        };

        $registry->register(stdClass::class, $observer);

        $result = $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Created);

        self::assertTrue($result);
    }

    #[Test]
    public function hasObserversReturnsFalseForUnregistered(): void
    {
        $registry = new EntityObserverRegistry();

        self::assertFalse($registry->hasObservers(stdClass::class));
    }

    #[Test]
    public function hasObserversReturnsTrueAfterRegister(): void
    {
        $registry = new EntityObserverRegistry();
        $observer = $this->createStub(EntityObserverInterface::class);

        $registry->register(stdClass::class, $observer);

        self::assertTrue($registry->hasObservers(stdClass::class));
    }

    #[Test]
    public function clearForRemovesObserversOfSpecificClass(): void
    {
        $registry = new EntityObserverRegistry();
        $observer = $this->createStub(EntityObserverInterface::class);

        $registry->register(stdClass::class, $observer);
        $registry->clearFor(stdClass::class);

        self::assertFalse($registry->hasObservers(stdClass::class));
    }

    #[Test]
    public function clearRemovesAllObservers(): void
    {
        $registry = new EntityObserverRegistry();
        $observer = $this->createStub(EntityObserverInterface::class);

        $registry->register(stdClass::class, $observer);
        $registry->register(ArrayObject::class, $observer);

        $registry->clear();

        self::assertFalse($registry->hasObservers(stdClass::class));
        self::assertFalse($registry->hasObservers(ArrayObject::class));
    }

    #[Test]
    public function multipleObserversAreCalledInOrder(): void
    {
        $registry = new EntityObserverRegistry();

        $first = new class implements EntityObserverInterface {
            public string $label = 'first';
            public bool $wasCalled = false;

            public function handle(object $entity, EntityEvent $event): bool
            {
                $this->wasCalled = true;

                return true;
            }
        };

        $second = new class implements EntityObserverInterface {
            public string $label = 'second';
            public bool $wasCalled = false;

            public function handle(object $entity, EntityEvent $event): bool
            {
                $this->wasCalled = true;

                return true;
            }
        };

        $registry->register(stdClass::class, $first);
        $registry->register(stdClass::class, $second);

        $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Updated);

        self::assertTrue($first->wasCalled);
        self::assertTrue($second->wasCalled);
    }

    #[Test]
    public function vetoingPreEventStopsSubsequentObservers(): void
    {
        $registry = new EntityObserverRegistry();

        $vetoer = new class implements EntityObserverInterface {
            public function handle(object $entity, EntityEvent $event): bool
            {
                return false;
            }
        };

        $checker = new class implements EntityObserverInterface {
            public bool $wasCalled = false;

            public function handle(object $entity, EntityEvent $event): bool
            {
                $this->wasCalled = true;

                return true;
            }
        };

        $registry->register(stdClass::class, $vetoer);
        $registry->register(stdClass::class, $checker);

        $registry->dispatch(stdClass::class, new stdClass(), EntityEvent::Deleting);

        self::assertFalse($checker->wasCalled);
    }
}
