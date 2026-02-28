<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\EntityNotFoundException;
use Pulsar\Extension\Orm\Exception\OrmException;

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
        $e = EntityNotFoundException::notFound('App\\Entity\\User', 42);

        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function notFoundByCriteriaIncludesDetails(): void
    {
        $e = EntityNotFoundException::notFoundByCriteria('App\\Entity\\Order', 'status=pending');

        self::assertStringContainsString('App\\Entity\\Order', $e->getMessage());
        self::assertStringContainsString('status=pending', $e->getMessage());
    }
}
