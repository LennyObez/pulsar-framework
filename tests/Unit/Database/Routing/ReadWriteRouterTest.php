<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function selectRoutesToRead(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    #[Test]
    public function insertRoutesToWrite(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('INSERT INTO users (name) VALUES ("test")'));
    }

    #[Test]
    public function updateRoutesToWrite(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('UPDATE users SET name = "test"'));
    }

    #[Test]
    public function deleteRoutesToWrite(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('DELETE FROM users WHERE id = 1'));
    }

    #[Test]
    public function ddlRoutesToWrite(): void
    {
        self::assertSame(ConnectionRole::Write, $this->router->route('CREATE TABLE users (id INT)'));
        self::assertSame(ConnectionRole::Write, $this->router->route('ALTER TABLE users ADD COLUMN name VARCHAR(255)'));
        self::assertSame(ConnectionRole::Write, $this->router->route('DROP TABLE users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('TRUNCATE TABLE users'));
    }

    #[Test]
    public function showRoutesToRead(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('SHOW TABLES'));
    }

    #[Test]
    public function describeRoutesToRead(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('DESCRIBE users'));
    }

    #[Test]
    public function explainRoutesToRead(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('EXPLAIN SELECT * FROM users'));
    }

    #[Test]
    public function pinToPrimaryOverridesReadRouting(): void
    {
        $this->router->pinToPrimary();

        self::assertSame(ConnectionRole::Write, $this->router->route('SELECT * FROM users'));
    }

    #[Test]
    public function pinExpiresAfterDuration(): void
    {
        // Pin for 1ms
        $this->router->pinToPrimary(1);

        self::assertTrue($this->router->isPinnedToPrimary());

        // Wait for expiration
        usleep(2000); // 2ms

        self::assertFalse($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    #[Test]
    public function resetClearsPin(): void
    {
        $this->router->pinToPrimary();

        self::assertTrue($this->router->isPinnedToPrimary());

        $this->router->reset();

        self::assertFalse($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Read, $this->router->route('SELECT * FROM users'));
    }

    #[Test]
    public function caseInsensitiveSqlClassification(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('select * from users'));
        self::assertSame(ConnectionRole::Read, $this->router->route('Select * From users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('insert into users (name) values ("test")'));
        self::assertSame(ConnectionRole::Write, $this->router->route('update users set name = "test"'));
        self::assertSame(ConnectionRole::Write, $this->router->route('delete from users where id = 1'));
    }

    #[Test]
    public function leadingWhitespaceIsTrimmed(): void
    {
        self::assertSame(ConnectionRole::Read, $this->router->route('  SELECT * FROM users'));
        self::assertSame(ConnectionRole::Write, $this->router->route('  INSERT INTO users (name) VALUES ("test")'));
    }

    #[Test]
    public function requestScopedPinPersistsUntilReset(): void
    {
        $this->router->pinToPrimary();

        self::assertTrue($this->router->isPinnedToPrimary());
        self::assertSame(ConnectionRole::Write, $this->router->route('SELECT * FROM users'));

        // Still pinned after time passes
        usleep(5000);
        self::assertTrue($this->router->isPinnedToPrimary());
    }
}
