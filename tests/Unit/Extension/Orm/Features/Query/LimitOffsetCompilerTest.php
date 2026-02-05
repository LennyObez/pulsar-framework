<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\LimitOffsetCompiler;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\PostgreSqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

#[CoversClass(LimitOffsetCompiler::class)]
final class LimitOffsetCompilerTest extends TestCase
{
    #[Test]
    public function compileLimitAndOffsetMySQL(): void
    {
        $compiler = new LimitOffsetCompiler(new MySqlDialect());

        self::assertSame(' LIMIT 10 OFFSET 20', $compiler->compile(10, 20));
    }

    #[Test]
    public function compileLimitOnlyMySQL(): void
    {
        $compiler = new LimitOffsetCompiler(new MySqlDialect());

        self::assertSame(' LIMIT 25', $compiler->compile(25, null));
    }

    #[Test]
    public function compileNullLimitAndOffsetMySQL(): void
    {
        $compiler = new LimitOffsetCompiler(new MySqlDialect());

        self::assertSame('', $compiler->compile(null, null));
    }

    #[Test]
    public function compileLimitAndOffsetPostgreSQL(): void
    {
        $compiler = new LimitOffsetCompiler(new PostgreSqlDialect());

        self::assertSame(' LIMIT 50 OFFSET 100', $compiler->compile(50, 100));
    }

    #[Test]
    public function compileLimitAndOffsetSQLite(): void
    {
        $compiler = new LimitOffsetCompiler(new SqliteDialect());

        self::assertSame(' LIMIT 10 OFFSET 5', $compiler->compile(10, 5));
    }

    #[Test]
    public function compileZeroOffsetIsIgnored(): void
    {
        $compiler = new LimitOffsetCompiler(new MySqlDialect());

        self::assertSame(' LIMIT 10', $compiler->compile(10, 0));
    }
}
