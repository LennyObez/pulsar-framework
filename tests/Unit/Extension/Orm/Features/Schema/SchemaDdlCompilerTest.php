<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Features\Schema\SchemaDdlCompiler;
use Pulsar\Extension\Orm\Features\Schema\TableBuilder;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

#[CoversClass(SchemaDdlCompiler::class)]
final class SchemaDdlCompilerTest extends TestCase
{
    private SchemaDdlCompiler $mysqlCompiler;
    private SchemaDdlCompiler $pgsqlCompiler;
    private SchemaDdlCompiler $sqliteCompiler;

    protected function setUp(): void
    {
        $mysqlQuoter = new IdentifierQuoter(Driver::MySQL);
        $this->mysqlCompiler = new SchemaDdlCompiler($mysqlQuoter, $mysqlQuoter->dialect());

        $pgsqlQuoter = new IdentifierQuoter(Driver::PostgreSQL);
        $this->pgsqlCompiler = new SchemaDdlCompiler($pgsqlQuoter, $pgsqlQuoter->dialect());

        $sqliteQuoter = new IdentifierQuoter(Driver::SQLite);
        $this->sqliteCompiler = new SchemaDdlCompiler($sqliteQuoter, $sqliteQuoter->dialect());
    }

    #[Test]
    public function compileCreateBasicTable(): void
    {
        $builder = new TableBuilder('users');
        $builder->id();
        $builder->string('name', 100);
        $builder->string('email');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('CREATE TABLE `users`', $sql);
        self::assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        self::assertStringContainsString('`name` VARCHAR(100) NOT NULL', $sql);
        self::assertStringContainsString('`email` VARCHAR(255) NOT NULL', $sql);
        self::assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    #[Test]
    public function compileCreateWithNullableAndDefaults(): void
    {
        $builder = new TableBuilder('settings');
        $builder->id();
        $builder->string('key');
        $builder->text('value')->nullable();
        $builder->boolean('active')->default(true);
        $builder->integer('priority')->default(0);

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('`value` TEXT', $sql);
        self::assertStringNotContainsString('`value` TEXT NOT NULL', $sql);
        self::assertStringContainsString('`active` TINYINT(1) NOT NULL DEFAULT 1', $sql);
        self::assertStringContainsString('`priority` INTEGER NOT NULL DEFAULT 0', $sql);
    }

    #[Test]
    public function compileCreateWithStringDefault(): void
    {
        $builder = new TableBuilder('config');
        $builder->id();
        $builder->string('locale')->default('en');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString("DEFAULT 'en'", $sql);
    }

    #[Test]
    public function compileCreateWithNullDefault(): void
    {
        $builder = new TableBuilder('events');
        $builder->id();
        $builder->dateTime('processed_at')->nullable()->default(null);

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('DEFAULT NULL', $sql);
    }

    #[Test]
    public function compileCreateWithUniqueIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->id();
        $builder->string('email');
        $builder->unique(['email'], 'uniq_users_email');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('CONSTRAINT `uniq_users_email` UNIQUE (`email`)', $sql);
    }

    #[Test]
    public function compileCreateWithNonUniqueIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->id();
        $builder->string('status');
        $builder->index(['status'], 'idx_users_status');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('CREATE INDEX `idx_users_status` ON `users` (`status`)', $sql);
    }

    #[Test]
    public function compileCreateWithForeignKey(): void
    {
        $builder = new TableBuilder('posts');
        $builder->id();
        $builder->bigInteger('user_id');
        $builder->foreign(['user_id'], 'users', ['id'], 'CASCADE', 'CASCADE', 'fk_posts_user');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString(
            'CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
            $sql,
        );
    }

    #[Test]
    public function compileCreatePostgresqlBigIntAutoIncrement(): void
    {
        $builder = new TableBuilder('users');
        $builder->id();

        $sql = $this->pgsqlCompiler->compileCreate($builder);

        self::assertStringContainsString('"id" BIGSERIAL', $sql);
        // Postgres auto-increment via BIGSERIAL, no AUTOINCREMENT keyword
        self::assertStringNotContainsString('AUTOINCREMENT', $sql);
        self::assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    #[Test]
    public function compileCreatePostgresqlTypes(): void
    {
        $builder = new TableBuilder('test');
        $builder->id();
        $builder->boolean('active');
        $builder->float('score');
        $builder->dateTime('created_at');
        $builder->json('metadata');
        $builder->binary('data');

        $sql = $this->pgsqlCompiler->compileCreate($builder);

        self::assertStringContainsString('BOOLEAN', $sql);
        self::assertStringContainsString('DOUBLE PRECISION', $sql);
        self::assertStringContainsString('TIMESTAMP', $sql);
        self::assertStringContainsString('JSONB', $sql);
        self::assertStringContainsString('BYTEA', $sql);
    }

    #[Test]
    public function compileCreateSqliteTypes(): void
    {
        $builder = new TableBuilder('test');
        $builder->id();
        $builder->json('metadata');
        $builder->binary('data');

        $sql = $this->sqliteCompiler->compileCreate($builder);

        // SQLite: JSON -> TEXT, Binary -> BLOB
        self::assertStringContainsString('"metadata" TEXT', $sql);
        self::assertStringContainsString('"data" BLOB', $sql);
    }

    #[Test]
    public function compileCreateSqliteAutoIncrement(): void
    {
        $builder = new TableBuilder('items');
        $builder->id();

        $sql = $this->sqliteCompiler->compileCreate($builder);

        self::assertStringContainsString('AUTOINCREMENT', $sql);
    }

    #[Test]
    public function compileCreateWithUniqueColumn(): void
    {
        $builder = new TableBuilder('users');
        $builder->id();
        $builder->string('email')->unique();

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('`email` VARCHAR(255) NOT NULL UNIQUE', $sql);
    }

    #[Test]
    public function compileCreateWithUnsignedColumn(): void
    {
        $builder = new TableBuilder('products');
        $builder->id();
        $builder->integer('quantity')->unsigned();

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('`quantity` INTEGER UNSIGNED NOT NULL', $sql);
    }

    #[Test]
    public function compileCreateDecimalColumn(): void
    {
        $builder = new TableBuilder('products');
        $builder->id();
        $builder->decimal('price', 10, 4);

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('`price` DECIMAL(10, 4)', $sql);
    }

    #[Test]
    public function compileCreateWithAllColumnTypes(): void
    {
        $builder = new TableBuilder('full_test');
        $builder->id();
        $builder->string('name');
        $builder->text('description');
        $builder->integer('count');
        $builder->smallInteger('rank');
        $builder->bigInteger('total');
        $builder->float('ratio');
        $builder->decimal('amount', 12, 2);
        $builder->boolean('is_active');
        $builder->dateTime('event_at');
        $builder->date('birth_date');
        $builder->time('start_time');
        $builder->json('config');
        $builder->binary('avatar');
        $builder->enum('status');

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('VARCHAR', $sql);
        self::assertStringContainsString('TEXT', $sql);
        self::assertStringContainsString('INTEGER', $sql);
        self::assertStringContainsString('SMALLINT', $sql);
        self::assertStringContainsString('BIGINT', $sql);
        self::assertStringContainsString('FLOAT', $sql);
        self::assertStringContainsString('DECIMAL(12, 2)', $sql);
        self::assertStringContainsString('TINYINT(1)', $sql);
        self::assertStringContainsString('DATETIME', $sql);
        self::assertStringContainsString('DATE', $sql);
        self::assertStringContainsString('TIME', $sql);
        self::assertStringContainsString('JSON', $sql);
        self::assertStringContainsString('BLOB', $sql);
    }

    #[Test]
    public function compileCreatePostgresUuid(): void
    {
        $builder = new TableBuilder('users');
        $builder->uuid();

        $sql = $this->pgsqlCompiler->compileCreate($builder);

        self::assertStringContainsString('UUID', $sql);
    }

    #[Test]
    public function compileCreateMysqlUuid(): void
    {
        $builder = new TableBuilder('users');
        $builder->uuid();

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('VARCHAR(36)', $sql);
    }

    #[Test]
    public function compileCreateWithFloatDefault(): void
    {
        $builder = new TableBuilder('settings');
        $builder->id();
        $builder->float('rate')->default(0.5);

        $sql = $this->mysqlCompiler->compileCreate($builder);

        self::assertStringContainsString('DEFAULT 0.5', $sql);
    }

    #[Test]
    public function compileAlterAddColumn(): void
    {
        $builder = new TableBuilder('users');
        $builder->string('phone', 20)->nullable();

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString('ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20)', $statements[0]);
    }

    #[Test]
    public function compileAlterDropColumn(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropColumn('legacy_field');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString('ALTER TABLE `users` DROP COLUMN `legacy_field`', $statements[0]);
    }

    #[Test]
    public function compileAlterDropIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropIndex('idx_users_email');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString('DROP INDEX `idx_users_email`', $statements[0]);
    }

    #[Test]
    public function compileAlterAddIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->index(['name', 'email'], 'idx_users_name_email');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString(
            'CREATE INDEX `idx_users_name_email` ON `users` (`name`, `email`)',
            $statements[0],
        );
    }

    #[Test]
    public function compileAlterAddUniqueIndex(): void
    {
        $builder = new TableBuilder('users');
        $builder->unique(['email'], 'uniq_users_email');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX `uniq_users_email` ON `users` (`email`)',
            $statements[0],
        );
    }

    #[Test]
    public function compileAlterAddForeignKey(): void
    {
        $builder = new TableBuilder('posts');
        $builder->foreign(['user_id'], 'users', ['id'], 'CASCADE', 'RESTRICT', 'fk_posts_user');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        self::assertNotEmpty($statements);
        self::assertStringContainsString('ALTER TABLE `posts` ADD CONSTRAINT', $statements[0]);
        self::assertStringContainsString('FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)', $statements[0]);
    }

    #[Test]
    public function compileAlterMultipleOperations(): void
    {
        $builder = new TableBuilder('users');
        $builder->dropIndex('idx_old');
        $builder->dropColumn('deprecated');
        $builder->string('new_field');
        $builder->index(['new_field'], 'idx_new_field');

        $statements = $this->mysqlCompiler->compileAlter($builder);

        // Drop indexes first, then drop columns, then add columns, then add indexes
        self::assertCount(4, $statements);
        self::assertStringContainsString('DROP INDEX', $statements[0]);
        self::assertStringContainsString('DROP COLUMN', $statements[1]);
        self::assertStringContainsString('ADD COLUMN', $statements[2]);
        self::assertStringContainsString('CREATE INDEX', $statements[3]);
    }

    #[Test]
    public function compileCreateWithBooleanDefaultFalse(): void
    {
        $builder = new TableBuilder('features');
        $builder->id();
        $builder->boolean('enabled')->default(false);

        $sql = $this->pgsqlCompiler->compileCreate($builder);

        self::assertStringContainsString('DEFAULT FALSE', $sql);
    }

    #[Test]
    public function compileCreatePostgresqlBooleanDefaultTrue(): void
    {
        $builder = new TableBuilder('features');
        $builder->id();
        $builder->boolean('active')->default(true);

        $sql = $this->pgsqlCompiler->compileCreate($builder);

        self::assertStringContainsString('DEFAULT TRUE', $sql);
    }
}
