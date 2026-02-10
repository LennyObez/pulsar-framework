<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Domain\LockMode;
use Pulsar\Extension\Orm\Internal\Compiler\MySqlDialect;
use Pulsar\Extension\Orm\Internal\Compiler\SqlCompiler;

#[CoversClass(SqlCompiler::class)]
final class SqlCompilerTest extends TestCase
{
    private SqlCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SqlCompiler(Driver::MySQL);
    }

    #[Test]
    public function compileSelectBasic(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
        );

        self::assertSame('SELECT * FROM `users`', $sql);
    }

    #[Test]
    public function compileSelectWithColumnsAndWhere(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`id`', '`name`'],
            from: '`users`',
            wheres: ['`status` = :p0'],
        );

        self::assertSame('SELECT `id`, `name` FROM `users` WHERE `status` = :p0', $sql);
    }

    #[Test]
    public function compileSelectWithJoins(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`u`.`id`', '`p`.`title`'],
            from: '`users` AS `u`',
            joins: ['JOIN `posts` AS `p` ON `p`.`user_id` = `u`.`id`'],
        );

        self::assertStringContainsString('JOIN `posts`', $sql);
    }

    #[Test]
    public function compileSelectWithGroupByAndHaving(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`status`', 'COUNT(*) AS `cnt`'],
            from: '`users`',
            groupBy: ['`status`'],
            havings: ['COUNT(*) > :p0'],
        );

        self::assertStringContainsString('GROUP BY `status`', $sql);
        self::assertStringContainsString('HAVING COUNT(*) > :p0', $sql);
    }

    #[Test]
    public function compileSelectWithOrderByAndLimitOffset(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
            orderBy: ['`created_at` DESC'],
            limit: 10,
            offset: 20,
        );

        self::assertStringContainsString('ORDER BY `created_at` DESC', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);
        self::assertStringContainsString('OFFSET 20', $sql);
    }

    #[Test]
    public function compileSelectWithLockMode(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
            wheres: ['`id` = :p0'],
            lock: LockMode::ForUpdate,
        );

        self::assertStringEndsWith('FOR UPDATE', $sql);
    }

    #[Test]
    public function compileInsert(): void
    {
        $sql = $this->compiler->compileInsert(
            table: '`users`',
            columns: ['name', 'email'],
            placeholders: [':p0', ':p1'],
        );

        self::assertSame('INSERT INTO `users` (`name`, `email`) VALUES (:p0, :p1)', $sql);
    }

    #[Test]
    public function compileUpdate(): void
    {
        $sql = $this->compiler->compileUpdate(
            table: '`users`',
            setClauses: ['`name` = :p0', '`email` = :p1'],
            wheres: ['`id` = :p2'],
        );

        self::assertSame('UPDATE `users` SET `name` = :p0, `email` = :p1 WHERE `id` = :p2', $sql);
    }

    #[Test]
    public function compileUpdateWithoutWhere(): void
    {
        $sql = $this->compiler->compileUpdate(
            table: '`users`',
            setClauses: ['`active` = :p0'],
        );

        self::assertSame('UPDATE `users` SET `active` = :p0', $sql);
    }

    #[Test]
    public function compileDelete(): void
    {
        $sql = $this->compiler->compileDelete(
            table: '`users`',
            wheres: ['`id` = :p0'],
        );

        self::assertSame('DELETE FROM `users` WHERE `id` = :p0', $sql);
    }

    #[Test]
    public function compileDeleteWithoutWhere(): void
    {
        $sql = $this->compiler->compileDelete(table: '`users`');

        self::assertSame('DELETE FROM `users`', $sql);
    }

    #[Test]
    public function quoterReturnsIdentifierQuoter(): void
    {
        $quoter = $this->compiler->quoter();
        self::assertInstanceOf(\Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter::class, $quoter);
    }

    #[Test]
    public function dialectReturnsMysqlDialect(): void
    {
        self::assertInstanceOf(MySqlDialect::class, $this->compiler->dialect());
    }

    #[Test]
    public function compileSelectWithMultipleWheres(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
            wheres: ['`status` = :p0', '`age` > :p1', '`deleted_at` IS NULL'],
        );

        self::assertSame(
            'SELECT * FROM `users` WHERE `status` = :p0 AND `age` > :p1 AND `deleted_at` IS NULL',
            $sql,
        );
    }

    #[Test]
    public function compileSelectSqliteDriver(): void
    {
        $sqliteCompiler = new SqlCompiler(Driver::SQLite);

        $sql = $sqliteCompiler->compileSelect(
            columns: ['*'],
            from: '"users"',
            limit: 5,
            lock: LockMode::ForUpdate,
        );

        // SQLite ignores locks
        self::assertStringContainsString('LIMIT 5', $sql);
        self::assertStringNotContainsString('FOR UPDATE', $sql);
    }
}
