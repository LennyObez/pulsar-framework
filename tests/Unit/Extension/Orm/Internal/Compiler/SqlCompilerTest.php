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

    #[Test]
    public function compileSelectPostgresDriver(): void
    {
        $pgCompiler = new SqlCompiler(Driver::PostgreSQL);

        $sql = $pgCompiler->compileSelect(
            columns: ['"id"', '"name"'],
            from: '"users"',
            wheres: ['"status" = :p0'],
            lock: LockMode::ForShare,
        );

        self::assertStringContainsString('SELECT "id", "name" FROM "users"', $sql);
        self::assertStringContainsString('WHERE "status" = :p0', $sql);
        self::assertStringEndsWith('FOR SHARE', $sql);
    }

    #[Test]
    public function compileSelectWithAllClauses(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`u`.`id`', 'COUNT(`o`.`id`) AS `order_count`'],
            from: '`users` AS `u`',
            joins: ['LEFT JOIN `orders` AS `o` ON `o`.`user_id` = `u`.`id`'],
            wheres: ['`u`.`active` = :p0'],
            groupBy: ['`u`.`id`'],
            havings: ['COUNT(`o`.`id`) > :p1'],
            orderBy: ['`order_count` DESC'],
            limit: 10,
            offset: 5,
            lock: LockMode::ForUpdate,
        );

        self::assertStringContainsString('SELECT `u`.`id`, COUNT(`o`.`id`) AS `order_count`', $sql);
        self::assertStringContainsString('FROM `users` AS `u`', $sql);
        self::assertStringContainsString('LEFT JOIN `orders` AS `o`', $sql);
        self::assertStringContainsString('WHERE `u`.`active` = :p0', $sql);
        self::assertStringContainsString('GROUP BY `u`.`id`', $sql);
        self::assertStringContainsString('HAVING COUNT(`o`.`id`) > :p1', $sql);
        self::assertStringContainsString('ORDER BY `order_count` DESC', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);
        self::assertStringContainsString('OFFSET 5', $sql);
        self::assertStringEndsWith('FOR UPDATE', $sql);
    }

    #[Test]
    public function compileSelectWithLimitOnlyNoOffset(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
            limit: 100,
        );

        self::assertStringContainsString('LIMIT 100', $sql);
        self::assertStringNotContainsString('OFFSET', $sql);
    }

    #[Test]
    public function compileSelectWithForShareLockMySQL(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`accounts`',
            wheres: ['`id` = :p0'],
            lock: LockMode::ForShare,
        );

        self::assertStringEndsWith('LOCK IN SHARE MODE', $sql);
    }

    #[Test]
    public function compileSelectWithMultipleJoins(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`u`.`id`', '`p`.`title`', '`c`.`name`'],
            from: '`users` AS `u`',
            joins: [
                'JOIN `posts` AS `p` ON `p`.`user_id` = `u`.`id`',
                'JOIN `categories` AS `c` ON `c`.`id` = `p`.`category_id`',
            ],
        );

        self::assertStringContainsString('JOIN `posts` AS `p`', $sql);
        self::assertStringContainsString('JOIN `categories` AS `c`', $sql);
    }

    #[Test]
    public function compileSelectWithMultipleOrderBy(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['*'],
            from: '`users`',
            orderBy: ['`last_name` ASC', '`first_name` ASC', '`id` DESC'],
        );

        self::assertStringContainsString(
            'ORDER BY `last_name` ASC, `first_name` ASC, `id` DESC',
            $sql,
        );
    }

    #[Test]
    public function compileInsertWithSingleColumn(): void
    {
        $sql = $this->compiler->compileInsert(
            table: '`settings`',
            columns: ['value'],
            placeholders: [':p0'],
        );

        self::assertSame('INSERT INTO `settings` (`value`) VALUES (:p0)', $sql);
    }

    #[Test]
    public function compileInsertQuotesColumns(): void
    {
        $sql = $this->compiler->compileInsert(
            table: '`users`',
            columns: ['first_name', 'last_name', 'email'],
            placeholders: [':p0', ':p1', ':p2'],
        );

        self::assertStringContainsString('`first_name`', $sql);
        self::assertStringContainsString('`last_name`', $sql);
        self::assertStringContainsString('`email`', $sql);
    }

    #[Test]
    public function compileUpdateWithMultipleWhereClauses(): void
    {
        $sql = $this->compiler->compileUpdate(
            table: '`users`',
            setClauses: ['`status` = :p0'],
            wheres: ['`role` = :p1', '`active` = :p2'],
        );

        self::assertSame(
            'UPDATE `users` SET `status` = :p0 WHERE `role` = :p1 AND `active` = :p2',
            $sql,
        );
    }

    #[Test]
    public function compileDeleteWithMultipleWhereClauses(): void
    {
        $sql = $this->compiler->compileDelete(
            table: '`users`',
            wheres: ['`deleted_at` IS NOT NULL', '`expired` = :p0'],
        );

        self::assertSame(
            'DELETE FROM `users` WHERE `deleted_at` IS NOT NULL AND `expired` = :p0',
            $sql,
        );
    }

    #[Test]
    public function compileSelectWithMultipleGroupBy(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`department`', '`role`', 'COUNT(*) AS `cnt`'],
            from: '`employees`',
            groupBy: ['`department`', '`role`'],
        );

        self::assertStringContainsString('GROUP BY `department`, `role`', $sql);
    }

    #[Test]
    public function compileSelectWithMultipleHavings(): void
    {
        $sql = $this->compiler->compileSelect(
            columns: ['`status`', 'COUNT(*) AS `cnt`', 'AVG(`salary`) AS `avg_sal`'],
            from: '`employees`',
            groupBy: ['`status`'],
            havings: ['COUNT(*) > :p0', 'AVG(`salary`) > :p1'],
        );

        self::assertStringContainsString('HAVING COUNT(*) > :p0 AND AVG(`salary`) > :p1', $sql);
    }
}
