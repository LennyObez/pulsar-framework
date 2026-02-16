<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\MappingException;
use Pulsar\Extension\Orm\Exception\OrmException;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class MappingExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(OrmException::class, MappingException::missingTable(UserEntity::class));
    }

    #[Test]
    public function missingTableIncludesClass(): void
    {
        $e = MappingException::missingTable(UserEntity::class);

        self::assertStringContainsString('UserEntity', $e->getMessage());
        self::assertStringContainsString('#[Table]', $e->getMessage());
    }

    #[Test]
    public function missingIdIncludesClass(): void
    {
        $e = MappingException::missingId(UserEntity::class);

        self::assertStringContainsString('UserEntity', $e->getMessage());
        self::assertStringContainsString('#[Id]', $e->getMessage());
    }

    #[Test]
    public function duplicateColumnIncludesDetails(): void
    {
        $e = MappingException::duplicateColumn(UserEntity::class, 'email');

        self::assertStringContainsString('UserEntity', $e->getMessage());
        self::assertStringContainsString('email', $e->getMessage());
    }

    #[Test]
    public function invalidRelationIncludesPropertyAndReason(): void
    {
        $e = MappingException::invalidRelation(PostEntity::class, 'tags', 'missing pivot');

        self::assertStringContainsString('PostEntity', $e->getMessage());
        self::assertStringContainsString('tags', $e->getMessage());
        self::assertStringContainsString('missing pivot', $e->getMessage());
    }

    #[Test]
    public function invalidUsesDirectMessage(): void
    {
        $e = MappingException::invalid('custom error');

        self::assertSame('custom error', $e->getMessage());
    }
}
