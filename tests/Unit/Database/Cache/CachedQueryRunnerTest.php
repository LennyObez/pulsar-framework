<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\CacheableQuery;
use Pulsar\Database\Cache\CachedQueryRunner;
use Pulsar\Database\Cache\QueryCache;
use Pulsar\Database\Cache\QueryCacheConfig;
use Pulsar\Database\Cache\SensitivityMetadata;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Tests\Unit\Database\Cache\Support\ArraySimpleCache;

/**
 * `query_cache` in `config/database.php` was parsed into a `QueryCacheConfig` that no
 * runtime object read. Every test here asserts one of its keys taking effect; without
 * a consumer, none of them could.
 */
#[CoversClass(CachedQueryRunner::class)]
final class CachedQueryRunnerTest extends TestCase
{
    private PdoConnection $connection;
    private ArraySimpleCache $store;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );
        $this->connection->execute('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL, user_id INTEGER)');
        $this->connection->execute("INSERT INTO articles (title, user_id) VALUES ('first', 1)");

        $this->store = new ArraySimpleCache();
    }

    #[Test]
    public function aSecondReadIsServedFromTheCache(): void
    {
        $runner = $this->runner(new QueryCacheConfig());

        self::assertSame('first', $this->title($runner));

        // Change the row behind the runner's back. A cached read must not see it.
        $this->connection->execute("UPDATE articles SET title = 'second' WHERE id = 1");

        self::assertSame('first', $this->title($runner));
    }

    /**
     * `enabled` is the setting the whole section is named for, and until there was a
     * consumer it changed nothing. False means the runner executes and returns without
     * touching the cache at all — not that it caches quietly.
     */
    #[Test]
    public function enabledFalseRunsTheQueryAndStoresNothing(): void
    {
        $runner = $this->runner(new QueryCacheConfig(enabled: false));

        self::assertSame('first', $this->title($runner));

        $this->connection->execute("UPDATE articles SET title = 'second' WHERE id = 1");

        self::assertSame('second', $this->title($runner));
        self::assertSame([], $this->store->entries());
    }

    /**
     * `default_ttl_seconds` is the lifetime of an entry whose caller named none.
     */
    #[Test]
    public function theDefaultTtlIsTheOneTheConfigNames(): void
    {
        $runner = $this->runner(new QueryCacheConfig(defaultTtlSeconds: 1234));

        $this->title($runner);

        self::assertSame([1234], $this->store->ttls());
    }

    #[Test]
    public function aCallerMayNameItsOwnTtl(): void
    {
        $runner = $this->runner(new QueryCacheConfig(defaultTtlSeconds: 1234));

        $runner->query('SELECT title FROM articles WHERE id = 1', [], ttlSeconds: 7);

        self::assertSame([7], $this->store->ttls());
    }

    /**
     * `sensitive_table_names` overrides the caller: a query touching one of them is
     * executed and never stored, whatever `enabled` says and whatever was asked for.
     */
    #[Test]
    public function aSensitiveTableIsNeverStored(): void
    {
        $this->connection->execute('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $this->connection->execute("INSERT INTO audit_logs (title) VALUES ('entry')");

        $runner = $this->runner(new QueryCacheConfig(sensitiveTableNames: ['audit_logs']));

        $runner->query('SELECT title FROM audit_logs WHERE id = 1');

        self::assertSame([], $this->store->entries());
    }

    /**
     * `authorization_columns` is the tenant-isolation guard: a query scoped by one of
     * them belongs to whoever asked, and a cache entry does not carry that scope.
     */
    #[Test]
    public function anAuthorizationShapedQueryIsNeverStored(): void
    {
        $runner = $this->runner(new QueryCacheConfig(authorizationColumns: ['user_id']));

        $runner->query('SELECT title FROM articles WHERE user_id = :u', ['u' => 1]);

        self::assertSame([], $this->store->entries());
    }

    /**
     * A column an operator did NOT list is not treated as authorization-shaped, so the
     * setting is observably a list rather than a fixed rule.
     */
    #[Test]
    public function aColumnTheOperatorDidNotListDoesNotBlockCaching(): void
    {
        $runner = $this->runner(new QueryCacheConfig(authorizationColumns: ['tenant_id']));

        $runner->query('SELECT title FROM articles WHERE user_id = :u', ['u' => 1]);

        self::assertNotSame([], $this->store->entries());
    }

    #[Test]
    public function invalidatingATableDropsTheResultsThatTouchedIt(): void
    {
        $runner = $this->runner(new QueryCacheConfig());

        self::assertSame('first', $this->title($runner));

        $this->connection->execute("UPDATE articles SET title = 'second' WHERE id = 1");
        $runner->invalidate('articles');

        self::assertSame('second', $this->title($runner));
    }

    #[Test]
    public function invalidatingNothingIsANoOp(): void
    {
        $runner = $this->runner(new QueryCacheConfig());

        $this->title($runner);
        $before = $this->store->entries();

        $runner->invalidate();

        self::assertSame($before, $this->store->entries());
    }

    /**
     * The explicit form carries its own tags, which is what an application built on the
     * query builder supplies instead of leaning on the regex extractor.
     */
    #[Test]
    public function anExplicitCacheableQueryUsesTheTagsItCarries(): void
    {
        $runner = $this->runner(new QueryCacheConfig());

        $runner->run(CacheableQuery::forQuery(
            'SELECT title FROM articles WHERE id = 1',
            [],
            60,
            ['articles', 'authors'],
        ));

        $this->connection->execute("UPDATE articles SET title = 'second' WHERE id = 1");
        $runner->invalidate('authors');

        self::assertSame(
            'second',
            $runner->run(CacheableQuery::forQuery(
                'SELECT title FROM articles WHERE id = 1',
                [],
                60,
                ['articles', 'authors'],
            ))->firstOrFail()->getString('title'),
        );
    }

    private function runner(QueryCacheConfig $config): CachedQueryRunner
    {
        return new CachedQueryRunner(
            $this->connection,
            new QueryCache($this->store),
            new SensitivityMetadata($config),
            $config,
        );
    }

    private function title(CachedQueryRunner $runner): string
    {
        return $runner->query('SELECT title FROM articles WHERE id = 1')->firstOrFail()->getString('title');
    }
}
