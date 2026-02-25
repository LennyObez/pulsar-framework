<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\MappingException;
use Pulsar\Extension\Orm\Exception\OrmException;

final class MappingExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(OrmException::class, MappingException::missingTable('X'));
    }

    #[Test]
    public function missingTableIncludesClass(): void
    {
        $e = MappingException::missingTable('App\\Entity\\User');

        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('#[Table]', $e->getMessage());
    }

    #[Test]
    public function missingIdIncludesClass(): void
    {
        $e = MappingException::missingId('App\\Entity\\User');

        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('#[Id]', $e->getMessage());
    }

    #[Test]
    public function duplicateColumnIncludesDetails(): void
    {
        $e = MappingException::duplicateColumn('App\\Entity\\User', 'email');

        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('email', $e->getMessage());
    }

    #[Test]
    public function invalidRelationIncludesPropertyAndReason(): void
    {
        $e = MappingException::invalidRelation('App\\Entity\\Post', 'tags', 'missing pivot');

        self::assertStringContainsString('Post', $e->getMessage());
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
