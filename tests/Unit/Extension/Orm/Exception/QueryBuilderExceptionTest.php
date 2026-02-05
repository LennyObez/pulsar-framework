<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

#[CoversClass(QueryBuilderException::class)]
final class QueryBuilderExceptionTest extends TestCase
{
    #[Test]
    public function noTableProducesCorrectMessage(): void
    {
        $e = QueryBuilderException::noTable();

        self::assertSame('No table specified for query', $e->getMessage());
    }

    #[Test]
    public function invalidIdentifierIncludesIdentifier(): void
    {
        $e = QueryBuilderException::invalidIdentifier('bad-name');

        self::assertStringContainsString('bad-name', $e->getMessage());
        self::assertStringContainsString('Invalid SQL identifier', $e->getMessage());
    }

    #[Test]
    public function unqualifiedJoinRefIncludesColumn(): void
    {
        $e = QueryBuilderException::unqualifiedJoinRef('id');

        self::assertStringContainsString('id', $e->getMessage());
        self::assertStringContainsString('qualified references', $e->getMessage());
    }

    #[Test]
    public function aliasConflictIncludesAlias(): void
    {
        $e = QueryBuilderException::aliasConflict('t0');

        self::assertStringContainsString('t0', $e->getMessage());
        self::assertStringContainsString('already in use', $e->getMessage());
    }

    #[Test]
    public function invalidUsesCustomMessage(): void
    {
        $e = QueryBuilderException::invalid('custom error message');

        self::assertSame('custom error message', $e->getMessage());
    }
}
