<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\OrmException;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class QueryBuilderExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(OrmException::class, QueryBuilderException::noTable());
    }

    #[Test]
    public function noTableMessage(): void
    {
        $e = QueryBuilderException::noTable();

        self::assertStringContainsString('No table', $e->getMessage());
    }

    #[Test]
    public function invalidIdentifierIncludesValue(): void
    {
        $e = QueryBuilderException::invalidIdentifier('bad-id');

        self::assertStringContainsString('bad-id', $e->getMessage());
    }

    #[Test]
    public function unqualifiedJoinRefIncludesColumn(): void
    {
        $e = QueryBuilderException::unqualifiedJoinRef('column');

        self::assertStringContainsString('column', $e->getMessage());
        self::assertStringContainsString('qualified', $e->getMessage());
    }

    #[Test]
    public function aliasConflictIncludesAlias(): void
    {
        $e = QueryBuilderException::aliasConflict('t0');

        self::assertStringContainsString('t0', $e->getMessage());
    }

    #[Test]
    public function invalidUsesDirectMessage(): void
    {
        $e = QueryBuilderException::invalid('custom error');

        self::assertSame('custom error', $e->getMessage());
    }
}
