<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;

final class SqlCompilerTest extends TestCase
{
    private SqlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SqlCompiler(Driver::SQLite);
    }

    #[Test]
    public function compileSelectWithColumnsAndTable(): void
    {
        $sql = $this->compiler->compileSelect(['*'], '"users"');

        self::assertSame('SELECT * FROM "users"', $sql);
    }

    #[Test]
    public function compileSelectWithJoins(): void
    {
        $sql = $this->compiler->compileSelect(
            ['*'],
            '"orders"',
            joins: ['INNER JOIN "users" ON "orders"."user_id" = "users"."id"'],
        );

        self::assertStringContainsString('INNER JOIN', $sql);
    }

    #[Test]
    public function compileSelectWithWheres(): void
    {
        $sql = $this->compiler->compileSelect(
            ['*'],
            '"posts"',
            wheres: ['"status" = :w0'],
        );

        self::assertStringContainsString('WHERE "status" = :w0', $sql);
    }

    #[Test]
    public function compileSelectWithGroupBy(): void
    {
        $sql = $this->compiler->compileSelect(
            ['"category"', 'COUNT(*)'],
            '"products"',
            groupBy: ['"category"'],
        );

        self::assertStringContainsString('GROUP BY "category"', $sql);
    }

    #[Test]
    public function compileSelectWithHaving(): void
    {
        $sql = $this->compiler->compileSelect(
            ['"type"', 'COUNT(*)'],
            '"items"',
            groupBy: ['"type"'],
            havings: ['COUNT(*) > 5'],
        );

        self::assertStringContainsString('HAVING COUNT(*) > 5', $sql);
    }

    #[Test]
    public function compileSelectWithOrderBy(): void
    {
        $sql = $this->compiler->compileSelect(
            ['*'],
            '"events"',
            orderBy: ['"created_at" DESC'],
        );

        self::assertStringContainsString('ORDER BY "created_at" DESC', $sql);
    }

    #[Test]
    public function compileSelectWithLimitAndOffset(): void
    {
        $sql = $this->compiler->compileSelect(
            ['*'],
            '"logs"',
            limit: 25,
            offset: 50,
        );

        self::assertStringContainsString('LIMIT 25', $sql);
        self::assertStringContainsString('OFFSET 50', $sql);
    }

    #[Test]
    public function compileInsert(): void
    {
        $sql = $this->compiler->compileInsert(
            '"users"',
            ['name', 'email'],
            [':p0', ':p1'],
        );

        self::assertStringContainsString('INSERT INTO "users"', $sql);
        self::assertStringContainsString('VALUES', $sql);
    }

    #[Test]
    public function compileUpdate(): void
    {
        $sql = $this->compiler->compileUpdate(
            '"users"',
            ['"name" = :s0', '"email" = :s1'],
            ['"id" = :w0'],
        );

        self::assertStringStartsWith('UPDATE "users" SET', $sql);
        self::assertStringContainsString('WHERE', $sql);
    }

    #[Test]
    public function compileUpdateWithoutWheres(): void
    {
        $sql = $this->compiler->compileUpdate(
            '"settings"',
            ['"value" = :s0'],
        );

        self::assertStringContainsString('UPDATE', $sql);
        self::assertStringNotContainsString('WHERE', $sql);
    }

    #[Test]
    public function compileDelete(): void
    {
        $sql = $this->compiler->compileDelete('"temp"', ['"id" = :w0']);

        self::assertSame('DELETE FROM "temp" WHERE "id" = :w0', $sql);
    }

    #[Test]
    public function compileDeleteWithoutWheres(): void
    {
        $sql = $this->compiler->compileDelete('"temp"');

        self::assertSame('DELETE FROM "temp"', $sql);
    }

    #[Test]
    public function quoterReturnsIdentifierQuoter(): void
    {
        self::assertInstanceOf(\Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter::class, $this->compiler->quoter());
    }

    #[Test]
    public function dialectReturnsDialectInterface(): void
    {
        self::assertInstanceOf(\Pulsar\Extension\Orm\Internal\Compiler\DialectInterface::class, $this->compiler->dialect());
    }
}
