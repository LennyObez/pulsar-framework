<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Forum\Service\ForumSearchServiceInterface;

use function ceil;
use function max;
use function min;
use function sprintf;
use function trim;

/**
 * Database-backed forum search with driver-specific full-text implementations.
 *
 * - PostgreSQL: tsvector/plainto_tsquery with ts_rank scoring
 * - MySQL: MATCH ... AGAINST in BOOLEAN MODE with FULLTEXT indexes
 * - SQLite: LIKE-based fallback for development/testing
 */
#[Internal(reason: 'Use ForumSearchServiceInterface for public API')]
final readonly class ForumSearchService implements ForumSearchServiceInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function search(
        string $query,
        ?string $categoryId = null,
        ?string $authorId = null,
        ?string $tag = null,
        ?bool $solved = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $query = trim($query);

        $driver = $this->connection->driver();

        $bindings = $this->buildBindings($query, $categoryId, $authorId, $tag, $solved, $from, $to);
        $whereClauses = $this->buildWhereClauses($driver, $query, $categoryId, $authorId, $tag, $solved, $from, $to);
        $joinClauses = $this->buildJoinClauses($driver, $query, $tag);
        $rankColumn = $this->buildRankColumn($driver, $query);

        $whereStr = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count query
        $countSql = sprintf(
            'SELECT COUNT(DISTINCT t.id) AS total FROM forum_threads t %s %s',
            $joinClauses,
            $whereStr,
        );

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        // Data query with rank
        $dataSql = sprintf(
            <<<'SQL'
                SELECT DISTINCT t.id, t.title, t.slug, t.category_id, t.author_id,
                       t.type, t.status, t.reply_count, t.view_count, t.vote_score,
                       t.solved_post_id, t.last_activity_at, t.created_at%s
                FROM forum_threads t
                %s
                %s
                ORDER BY %s
                LIMIT %d OFFSET %d
                SQL,
            $rankColumn !== '' ? ', ' . $rankColumn . ' AS relevance' : '',
            $joinClauses,
            $whereStr,
            $rankColumn !== '' ? 'relevance DESC, t.last_activity_at DESC' : 't.last_activity_at DESC',
            $perPage,
            $offset,
        );

        $dataResult = $this->connection->query($dataSql, $bindings);

        /** @var list<array<string, mixed>> $items */
        $items = $dataResult->map(static fn($row) => [
            'id' => $row->getString('id'),
            'title' => $row->getString('title'),
            'slug' => $row->getString('slug'),
            'category_id' => $row->getString('category_id'),
            'author_id' => $row->getString('author_id'),
            'type' => $row->getString('type'),
            'status' => $row->getString('status'),
            'reply_count' => $row->getInt('reply_count'),
            'view_count' => $row->getInt('view_count'),
            'vote_score' => $row->getInt('vote_score'),
            'is_solved' => $row->getNullableString('solved_post_id') !== null,
            'last_activity_at' => $row->getNullableString('last_activity_at'),
            'created_at' => $row->getString('created_at'),
        ]);

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    /**
     * Build the rank/scoring column expression based on driver.
     */
    private function buildRankColumn(Driver $driver, string $query): string
    {
        if ($query === '') {
            return '';
        }

        return match ($driver) {
            Driver::PostgreSQL => "ts_rank(t.search_vector, plainto_tsquery('english', :query))",
            Driver::MySQL => 'MATCH (t.title) AGAINST (:query IN BOOLEAN MODE)',
            Driver::SQLite => '1',
        };
    }

    /**
     * Build JOIN clauses for post-body search and tag filtering.
     */
    private function buildJoinClauses(Driver $driver, string $query, ?string $tag): string
    {
        $joins = [];

        // Join posts for body search when query is non-empty
        if ($query !== '') {
            $joins[] = 'LEFT JOIN forum_posts p ON p.thread_id = t.id AND p.deleted_at IS NULL';
        }

        // Join tags for tag filtering
        if ($tag !== null) {
            $joins[] = 'INNER JOIN forum_thread_tags tt ON tt.thread_id = t.id';
            $joins[] = 'INNER JOIN forum_tags tg ON tg.id = tt.tag_id';
        }

        return implode("\n", $joins);
    }

    /**
     * Build WHERE clause conditions based on filters and driver.
     *
     * @return list<string>
     */
    private function buildWhereClauses(
        Driver $driver,
        string $query,
        ?string $categoryId,
        ?string $authorId,
        ?string $tag,
        ?bool $solved,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): array {
        $clauses = ['t.deleted_at IS NULL'];

        if ($query !== '') {
            $clauses[] = match ($driver) {
                Driver::PostgreSQL => <<<'SQL'
                    (
                        t.search_vector @@ plainto_tsquery('english', :query)
                        OR p.search_vector @@ plainto_tsquery('english', :query)
                    )
                    SQL,
                Driver::MySQL => <<<'SQL'
                    (
                        MATCH (t.title) AGAINST (:query IN BOOLEAN MODE)
                        OR p.body LIKE CONCAT('%', :query_like, '%')
                    )
                    SQL,
                Driver::SQLite => <<<'SQL'
                    (
                        t.title LIKE '%' || :query_like || '%'
                        OR p.body LIKE '%' || :query_like || '%'
                    )
                    SQL,
            };
        }

        if ($categoryId !== null) {
            $clauses[] = 't.category_id = :category_id';
        }

        if ($authorId !== null) {
            $clauses[] = 't.author_id = :author_id';
        }

        if ($tag !== null) {
            $clauses[] = 'tg.slug = :tag_slug';
        }

        if ($solved === true) {
            $clauses[] = 't.solved_post_id IS NOT NULL';
        } elseif ($solved === false) {
            $clauses[] = 't.solved_post_id IS NULL';
        }

        if ($from !== null) {
            $clauses[] = 't.created_at >= :from_date';
        }

        if ($to !== null) {
            $clauses[] = 't.created_at <= :to_date';
        }

        return $clauses;
    }

    /**
     * Build query parameter bindings.
     *
     * @return array<string, mixed>
     */
    private function buildBindings(
        string $query,
        ?string $categoryId,
        ?string $authorId,
        ?string $tag,
        ?bool $solved,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): array {
        $bindings = [];

        if ($query !== '') {
            $bindings['query'] = $query;

            $driver = $this->connection->driver();

            if ($driver === Driver::MySQL || $driver === Driver::SQLite) {
                $bindings['query_like'] = $query;
            }
        }

        if ($categoryId !== null) {
            $bindings['category_id'] = $categoryId;
        }

        if ($authorId !== null) {
            $bindings['author_id'] = $authorId;
        }

        if ($tag !== null) {
            $bindings['tag_slug'] = $tag;
        }

        if ($from !== null) {
            $bindings['from_date'] = $from->format('c');
        }

        if ($to !== null) {
            $bindings['to_date'] = $to->format('c');
        }

        return $bindings;
    }
}
