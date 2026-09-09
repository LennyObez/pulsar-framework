<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Result;
use Pulsar\Database\Routing\ReadWriteConfig;
use Pulsar\Database\Routing\ReadWriteRouter;
use Pulsar\Database\Routing\RoutingConnection;
use Pulsar\Database\Routing\RoutingConnectionManager;
use Pulsar\Database\Routing\StickinessContext;

/**
 * The primary is a real SQLite connection so transactions, `lastInsertId()` and the
 * depth counter behave the way a driver behaves; the replica is a stub that answers
 * every read with its own name, which is how each test says where a statement landed.
 */
#[CoversClass(RoutingConnection::class)]
final class RoutingConnectionTest extends TestCase
{
    private PdoConnection $primary;
    private ConnectionInterface&Stub $replica;
    private RoutingConnectionManager $manager;

    protected function setUp(): void
    {
        $this->primary = new PdoConnection(
            connectionName: 'primary',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );
        $this->primary->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, who TEXT NOT NULL)');
        $this->primary->execute("INSERT INTO users (who) VALUES ('primary')");

        $this->replica = $this->createStub(ConnectionInterface::class);
        $this->replica->method('name')->willReturn('replica');
        $this->replica->method('query')->willReturn(Result::fromArrays([['who' => 'replica']]));

        $inner = $this->createStub(ConnectionManagerInterface::class);
        $inner->method('connection')->willReturnMap([
            [null, $this->primary],
            ['read:replica-1', $this->replica],
        ]);

        $this->manager = new RoutingConnectionManager(
            $inner,
            new ReadWriteRouter(),
            new StickinessContext(),
            new ReadWriteConfig(readHosts: ['read:replica-1'], enabled: true),
        );
    }

    /**
     * The regression this class exists for.
     *
     * Nothing in the framework called `connectionForQuery()`. The container resolved
     * `ConnectionInterface` once, from `connection()`, and every caller shared that one
     * connection — so a deployment with routing enabled and replicas configured sent
     * every SELECT to the primary and the replicas went unused.
     */
    #[Test]
    public function aSelectReachesTheReplica(): void
    {
        $connection = new RoutingConnection($this->manager);

        self::assertSame('replica', $this->who($connection->query('SELECT who FROM users')));
    }

    #[Test]
    public function aWriteReachesThePrimary(): void
    {
        $connection = new RoutingConnection($this->manager);

        self::assertSame(1, $connection->execute("UPDATE users SET who = 'primary' WHERE id = 1"));
    }

    /**
     * Read-after-write: once a write has happened, reads stay on the primary for the
     * sticky duration rather than asking a replica that may not have caught up.
     */
    #[Test]
    public function aReadAfterAWriteStaysOnThePrimary(): void
    {
        $connection = new RoutingConnection($this->manager);

        $connection->execute("INSERT INTO users (who) VALUES ('primary')");

        self::assertSame('primary', $this->who($connection->query('SELECT who FROM users')));
    }

    /**
     * A transaction is routed as `BEGIN`, so it opens on the primary AND marks the
     * stickiness that keeps reads off a replica afterwards. Without the mark, a commit
     * followed by a SELECT would read a replica that had not yet received the write:
     * the transaction's own statements never pass through the router, because the
     * manager short-circuits them to the connection the transaction is pinned to.
     */
    #[Test]
    public function aTransactionOpensOnThePrimaryAndKeepsLaterReadsThere(): void
    {
        $connection = new RoutingConnection($this->manager);

        $transaction = $connection->beginTransaction();
        $connection->execute("INSERT INTO users (who) VALUES ('primary')");

        self::assertTrue($connection->inTransaction());
        self::assertSame('primary', $this->who($connection->query('SELECT who FROM users')));

        $transaction->commit();

        self::assertFalse($connection->inTransaction());
        self::assertSame('primary', $this->who($connection->query('SELECT who FROM users')));
    }

    #[Test]
    public function theCallbackFormRunsOnThePrimaryToo(): void
    {
        $connection = new RoutingConnection($this->manager);

        $name = $connection->transaction(
            static fn(ConnectionInterface $conn): string => $conn->name(),
        );

        self::assertSame('primary', $name);
        self::assertSame('primary', $this->who($connection->query('SELECT who FROM users')));
    }

    /**
     * A pending single-query override belongs to the next STATEMENT. A query builder
     * asking the connection for its dialect must not consume it, or the override would
     * apply to nothing and the read would silently go somewhere else.
     */
    #[Test]
    public function askingTheConnectionAboutItselfDoesNotConsumeAnOverride(): void
    {
        $connection = new RoutingConnection($this->manager);

        $this->manager->usePrimary();
        self::assertInstanceOf(DialectInterface::class, $connection->dialect());
        self::assertSame('primary', $connection->name());
        self::assertFalse($connection->inTransaction());

        // usePrimary() is still pending, so this SELECT goes to the primary.
        self::assertSame('primary', $this->who($connection->query('SELECT who FROM users')));
    }

    /**
     * `lastInsertId()` has to reach the connection the INSERT ran on, which is the
     * primary — a replica's answer would be a different sequence, or none.
     */
    #[Test]
    public function lastInsertIdReachesTheConnectionThatWrote(): void
    {
        $connection = new RoutingConnection($this->manager);

        $connection->execute("INSERT INTO users (who) VALUES ('primary')");

        self::assertSame('2', $connection->lastInsertId());
    }

    #[Test]
    public function theDriverAndVariantComeFromTheRoutedConnection(): void
    {
        $connection = new RoutingConnection($this->manager);

        self::assertSame(Driver::SQLite, $connection->driver());
        self::assertSame($this->primary->variant(), $connection->variant());
    }

    private function who(Result $result): string
    {
        return $result->firstOrFail()->getString('who');
    }
}
