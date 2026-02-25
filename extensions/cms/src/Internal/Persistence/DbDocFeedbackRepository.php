<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Docs\DocFeedback;
use Pulsar\Extension\Cms\Docs\DocFeedbackRepositoryInterface;

use function ceil;
use function max;

/**
 * @psalm-api Bound to DocFeedbackRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Raw-DB repository; use DocFeedbackRepositoryInterface for public API')]
final readonly class DbDocFeedbackRepository implements DocFeedbackRepositoryInterface
{
    private const string SQL_COUNT_BY_DOC_PAGE = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_doc_feedback
        WHERE doc_page_id = :doc_page_id
        SQL;

    private const string SQL_FIND_BY_DOC_PAGE = <<<'SQL'
        SELECT *
        FROM cms_doc_feedback
        WHERE doc_page_id = :doc_page_id
        ORDER BY created_at DESC
        SQL;

    private const string SQL_COUNT_HELPFUL = <<<'SQL'
        SELECT
            is_helpful,
            COUNT(*) AS cnt
        FROM cms_doc_feedback
        WHERE doc_page_id = :doc_page_id
        GROUP BY is_helpful
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'doc_page_id', 'user_id', 'is_helpful', 'comment', 'created_at',
    ];

    private const array UPSERT_UPDATE = ['is_helpful', 'comment'];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(DocFeedback $feedback): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_doc_feedback',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $feedback->id,
            'doc_page_id' => $feedback->docPageId,
            'user_id' => $feedback->userId,
            'is_helpful' => $feedback->isHelpful,
            'comment' => $feedback->comment,
            'created_at' => $feedback->createdAt->format('c'),
        ]);
    }

    /**
     * @return PaginationResult<DocFeedback>
     */
    public function findByDocPage(string $docPageId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_DOC_PAGE, [
            'doc_page_id' => $docPageId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = self::SQL_FIND_BY_DOC_PAGE . ' LIMIT :limit OFFSET :offset';
        $dataResult = $this->connection->query($selectSql, [
            'doc_page_id' => $docPageId,
            'limit' => $perPage,
            'offset' => $offset,
        ]);

        $items = $dataResult->map(self::hydrate(...));
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
     * @return array{helpful: int, not_helpful: int}
     */
    public function countByDocPage(string $docPageId): array
    {
        $result = $this->connection->query(self::SQL_COUNT_HELPFUL, [
            'doc_page_id' => $docPageId,
        ]);

        $counts = ['helpful' => 0, 'not_helpful' => 0];

        foreach ($result->map(static fn(Row $row): array => [
            'is_helpful' => $row->getBool('is_helpful'),
            'cnt' => $row->getInt('cnt'),
        ]) as $entry) {
            if ($entry['is_helpful']) {
                $counts['helpful'] = $entry['cnt'];
            } else {
                $counts['not_helpful'] = $entry['cnt'];
            }
        }

        return $counts;
    }

    private static function hydrate(Row $row): DocFeedback
    {
        return new DocFeedback(
            id: $row->getString('id'),
            docPageId: $row->getString('doc_page_id'),
            userId: $row->getNullableString('user_id'),
            isHelpful: $row->getBool('is_helpful'),
            comment: $row->getNullableString('comment'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
