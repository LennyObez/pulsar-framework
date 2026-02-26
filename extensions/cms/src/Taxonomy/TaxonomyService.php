<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;

use function array_values;

/**
 * Database-backed taxonomy service managing content-term associations.
 *
 * Operates on the {@code cms_content_taxonomy_terms} join table via
 * upsert-style inserts and targeted deletes wrapped in transactions.
 */
#[Internal(reason: 'Use TaxonomyServiceInterface for public API')]
final readonly class TaxonomyService implements TaxonomyServiceInterface
{
    private const string SQL_ATTACH = <<<'SQL'
        INSERT INTO cms_content_taxonomy_terms (content_id, term_id)
        VALUES (:content_id, :term_id)
        ON CONFLICT (content_id, term_id) DO NOTHING
        SQL;

    private const string SQL_DETACH = <<<'SQL'
        DELETE FROM cms_content_taxonomy_terms
        WHERE content_id = :content_id AND term_id = :term_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function attachTerms(string $contentId, array $termIds): void
    {
        $termIds = array_values($termIds);

        if ($termIds === []) {
            return;
        }

        $this->connection->transaction(function (ConnectionInterface $conn) use ($contentId, $termIds): void {
            foreach ($termIds as $termId) {
                $conn->execute(self::SQL_ATTACH, [
                    'content_id' => $contentId,
                    'term_id' => $termId,
                ]);
            }
        });
    }

    public function detachTerms(string $contentId, array $termIds): void
    {
        $termIds = array_values($termIds);

        if ($termIds === []) {
            return;
        }

        $this->connection->transaction(function (ConnectionInterface $conn) use ($contentId, $termIds): void {
            foreach ($termIds as $termId) {
                $conn->execute(self::SQL_DETACH, [
                    'content_id' => $contentId,
                    'term_id' => $termId,
                ]);
            }
        });
    }

    public function bulkTag(array $contentIds, string $termId): void
    {
        $contentIds = array_values($contentIds);

        if ($contentIds === []) {
            return;
        }

        $this->connection->transaction(function (ConnectionInterface $conn) use ($contentIds, $termId): void {
            foreach ($contentIds as $contentId) {
                $conn->execute(self::SQL_ATTACH, [
                    'content_id' => $contentId,
                    'term_id' => $termId,
                ]);
            }
        });
    }

    public function bulkUntag(array $contentIds, string $termId): void
    {
        $contentIds = array_values($contentIds);

        if ($contentIds === []) {
            return;
        }

        $this->connection->transaction(function (ConnectionInterface $conn) use ($contentIds, $termId): void {
            foreach ($contentIds as $contentId) {
                $conn->execute(self::SQL_DETACH, [
                    'content_id' => $contentId,
                    'term_id' => $termId,
                ]);
            }
        });
    }
}
