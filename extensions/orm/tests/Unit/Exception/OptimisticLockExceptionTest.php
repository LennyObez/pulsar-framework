<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\OptimisticLockException;
use Pulsar\Extension\Orm\Exception\OrmException;

final class OptimisticLockExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(
            OrmException::class,
            OptimisticLockException::versionMismatch('X', 1, 2, 3),
        );
    }

    #[Test]
    public function versionMismatchIncludesAllDetails(): void
    {
        $e = OptimisticLockException::versionMismatch('App\\Entity\\Order', 42, 5, 6);

        self::assertStringContainsString('App\\Entity\\Order', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('6', $e->getMessage());
    }

    #[Test]
    public function staleEntityIncludesClassAndId(): void
    {
        $e = OptimisticLockException::staleEntity('App\\Entity\\User', 'abc-123');

        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('abc-123', $e->getMessage());
    }
}
