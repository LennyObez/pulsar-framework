<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\CachedQueryRunner;
use Pulsar\Database\Cache\QueryCacheConfig;
use Pulsar\Database\Cache\QueryCacheInterface;
use Pulsar\Database\Cache\SensitivityMetadata;
use Pulsar\Database\Cache\TableTagExtractorInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Routing\QueryRouterInterface;
use Pulsar\Database\Routing\RoutingConnection;

use function array_values;
use function str_starts_with;

/**
 * Two collaborators a consumer could not previously replace.
 *
 * `composer class-shape` reported both as final-concrete dependency sites, which is its
 * name for a seam that looks like one and is not: the parameter is typed to a `final`
 * class, so the only thing that can be passed is that class.
 *
 * They were worth fixing rather than recording, for one reason each.
 *
 *  - `CachedQueryRunner` is `#[Api]`, and its correctness depends on the tag extractor's
 *    answer. A table the extractor misses is a table whose writes never invalidate the
 *    entries that read it, and the cache then serves a row that has since changed. An
 *    application whose SQL the shipped regular expression cannot read had no way to
 *    supply a better answer.
 *  - `RoutingConnection` is the only thing that decides where a statement goes, and it
 *    could not be exercised without a `RoutingConnectionManager` holding real
 *    connections. The routing policy and the connection decorator were one unit.
 *
 * Each test here constructs the consumer with an implementation that is emphatically not
 * the shipped class. Before the interfaces existed both lines were a `TypeError`.
 */
#[CoversNothing]
final class SubstitutableCollaboratorTest extends TestCase
{
    #[Test]
    public function theCachedQueryRunnerAcceptsAnotherTagExtractor(): void
    {
        $extractor = new class implements TableTagExtractorInterface {
            #[Override]
            public function extractTags(string $sql, ?array $queryBuilderContext = null): array
            {
                return ['ledger_entries', 'ledger_accounts'];
            }
        };

        $config = new QueryCacheConfig(enabled: true, defaultTtlSeconds: 60);
        $connection = $this->connectionReturning(Result::fromArrays([['n' => 1]]));
        $cache = new class implements QueryCacheInterface {
            /** @var list<string>|null */
            public ?array $lastTags = null;

            #[Override]
            public function get(string $key): ?Result
            {
                return null;
            }

            #[Override]
            public function put(string $key, Result $result, int $ttlSeconds, array $tags): void
            {
                $this->lastTags = array_values($tags);
            }

            #[Override]
            public function invalidateByTags(array $tags): void {}

            #[Override]
            public function flush(): void {}
        };

        $runner = new CachedQueryRunner(
            $connection,
            $cache,
            new SensitivityMetadata($config),
            $config,
            $extractor,
        );

        $runner->query('SELECT 1 FROM something_the_regex_cannot_read');

        self::assertSame(
            ['ledger_entries', 'ledger_accounts'],
            $cache->lastTags,
            'the runner used the shipped extractor instead of the one it was given',
        );
    }

    #[Test]
    public function theRoutingConnectionAcceptsAnotherRouter(): void
    {
        $replica = $this->connectionReturning(Result::fromArrays([['on' => 'replica']]));
        $primary = $this->connectionReturning(Result::fromArrays([['on' => 'primary']]));

        $router = new class ($replica, $primary) implements QueryRouterInterface {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(
                private readonly ConnectionInterface $replica,
                private readonly ConnectionInterface $primary,
            ) {}

            #[Override]
            public function connectionForQuery(string $sql): ConnectionInterface
            {
                $this->asked[] = $sql;

                return str_starts_with($sql, 'SELECT') ? $this->replica : $this->primary;
            }

            #[Override]
            public function routedConnection(): ConnectionInterface
            {
                return $this->primary;
            }

            #[Override]
            public function connection(?string $name = null): ConnectionInterface
            {
                return $this->primary;
            }

            #[Override]
            public function getDefaultConnectionName(): string
            {
                return 'fake';
            }

            #[Override]
            public function disconnect(?string $name = null): void {}
        };

        $connection = new RoutingConnection($router);

        self::assertSame(['on' => 'replica'], $connection->query('SELECT 1')->firstOrFail()->data);
        self::assertSame(['SELECT 1'], $router->asked, 'the decorator did not consult the router it was given');
    }

    /**
     * A connection that answers `query()` and refuses everything else.
     *
     * `createStub()` rather than `createMock()`: nothing here is asserting how the
     * connection was called -- the assertions are about which extractor and which router
     * the consumer consulted -- so a stub says what this is and a mock would only invite
     * PHPUnit to point out that no expectation was ever set.
     */
    private function connectionReturning(Result $result): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        return $connection;
    }
}
