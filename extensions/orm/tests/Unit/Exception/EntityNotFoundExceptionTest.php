<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\EntityNotFoundException;
use Pulsar\Extension\Orm\Exception\OrmException;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\OrderEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class EntityNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        $parents = class_parents(EntityNotFoundException::class);

        self::assertContains(OrmException::class, $parents);
    }

    #[Test]
    public function notFoundIncludesClassAndId(): void
    {
        $e = EntityNotFoundException::notFound(UserEntity::class, 42);

        self::assertStringContainsString('UserEntity', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function notFoundByCriteriaIncludesDetails(): void
    {
        $e = EntityNotFoundException::notFoundByCriteria(OrderEntity::class, 'status=pending');

        self::assertStringContainsString('OrderEntity', $e->getMessage());
        self::assertStringContainsString('status=pending', $e->getMessage());
    }
}
