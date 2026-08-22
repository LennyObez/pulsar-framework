<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\OrmException;
use RuntimeException;

final class OrmExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $parents = class_parents(OrmException::class);

        self::assertContains(RuntimeException::class, $parents);
    }

    #[Test]
    public function operationFailedIncludesOperation(): void
    {
        $e = OrmException::operationFailed('insert');

        self::assertStringContainsString('insert', $e->getMessage());
    }

    #[Test]
    public function operationFailedPreservesPrevious(): void
    {
        $prev = new RuntimeException('db down');
        $e = OrmException::operationFailed('query', $prev);

        self::assertSame($prev, $e->getPrevious());
    }

    #[Test]
    public function invalidConfigurationIncludesMessage(): void
    {
        $e = OrmException::invalidConfiguration('missing driver');

        self::assertStringContainsString('missing driver', $e->getMessage());
    }

    #[Test]
    public function unsupportedDriverIncludesDriverName(): void
    {
        $e = OrmException::unsupportedDriver('oracle');

        self::assertStringContainsString('oracle', $e->getMessage());
    }
}
