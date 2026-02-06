<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Routing\ConnectionRole;
use Pulsar\Database\Routing\ReadWriteRouter;

#[CoversClass(ReadWriteRouter::class)]
final class ReadWriteRouterTest extends TestCase
{
    private ReadWriteRouter $router;

    protected function setUp(): void
    {
        $this->router = new ReadWriteRouter();
    }

    public function test_select_routes_to_read(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    public function test_insert_routes_to_write(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('INSERT INTO users (name) VALUES ("test")'));
    }

    public function test_update_routes_to_write(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('UPDATE users SET name = "test"'));
    }

    public function test_delete_routes_to_write(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('DELETE FROM users WHERE id = 1'));
    }

    public function test_ddl_routes_to_write(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('CREATE TABLE users (id INT)'));
        self::assertSame(ConnectionRole::Write, $this->router->route('ALTER TABLE users ADD COLUMN name VARCHAR(255)'));
        self::assertSame(ConnectionRole::Write, $this->router->route('DROP TABLE users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('TRUNCATE TABLE users'));
    }

    public function test_show_routes_to_read(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('SHOW TABLES'));
    }

    public function test_describe_routes_to_read(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('DESCRIBE users'));
    }

    public function test_explain_routes_to_read(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('EXPLAIN SELECT * FROM users'));
    }

    public function test_pin_to_primary_overrides_read_routing(): void
    {
        $this->router->pinToPrimary();

        self::assertSame(ConnectionRole::Write, $this->router->route('SELECT * FROM users'));
    }

    public function test_pin_expires_after_duration(): void
    {
        // Pin for 1ms
        $this->router->pinToPrimary(1);

        self::assertTrue($this->router->isPinnedToPrimary());

        // Wait for expiration
        usleep(2000); // 2ms

        self::assertFalse($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    public function test_reset_clears_pin(): void
    {
        $this->router->pinToPrimary();

        self::assertTrue($this->router->isPinnedToPrimary());

        $this->router->reset();

        self::assertFalse($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    public function test_case_insensitive_sql_classification(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('select * from users'));
        self::assertSame(ConnectionRole::Read, $this->router->route('Select * From users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('insert into users (name) values ("test")'));
        self::assertSame(ConnectionRole::Write, $this->router->route('update users set name = "test"'));
        self::assertSame(ConnectionRole::Write, $this->router->route('delete from users where id = 1'));
    }

    public function test_leading_whitespace_is_trimmed(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('  SELECT * FROM users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('  INSERT INTO users (name) VALUES ("test")'));
    }

    public function test_request_scoped_pin_persists_until_reset(): void
    {
        $this->router->pinToPrimary();

        self::assertTrue($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Write, $this->router->route('SELECT * FROM users'));

        // Still pinned after time passes
        usleep(5000);
        self::assertTrue($this->router->isPinnedToPrimary());
    }
}
