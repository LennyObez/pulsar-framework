<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\OptimisticLockException;
use Pulsar\Extension\Orm\Exception\OrmException;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\FooEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\OrderEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class OptimisticLockExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(
            OrmException::class,
            OptimisticLockException::versionMismatch(FooEntity::class, 1, 2, 3),
        );
    }

    #[Test]
    public function versionMismatchIncludesAllDetails(): void
    {
        $e = OptimisticLockException::versionMismatch(OrderEntity::class, 42, 5, 6);

        self::assertStringContainsString('OrderEntity', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('6', $e->getMessage());
    }

    #[Test]
    public function staleEntityIncludesClassAndId(): void
    {
        $e = OptimisticLockException::staleEntity(UserEntity::class, 'abc-123');

        self::assertStringContainsString('UserEntity', $e->getMessage());
        self::assertStringContainsString('abc-123', $e->getMessage());
    }
}
