<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use ContentRevisionRepositoryInterface for public API')]
final readonly class DbContentRevisionRepository implements ContentRevisionRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_content_revisions WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_CONTENT_AND_LOCALE = <<<'SQL'
        SELECT * FROM cms_content_revisions
        WHERE content_id = :content_id AND locale = :locale
        ORDER BY revision_number DESC
        SQL;

    private const string SQL_LATEST_REVISION_NUMBER = <<<'SQL'
        SELECT COALESCE(MAX(revision_number), 0) AS latest
        FROM cms_content_revisions
        WHERE content_id = :content_id AND locale = :locale
        SQL;

    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO cms_content_revisions (
            id, content_id, locale, revision_number, title, slug, body,
            excerpt, meta_title, meta_description, author_id, reason,
            evidence_hash, created_at
        ) VALUES (
            :id, :content_id, :locale, :revision_number, :title, :slug, :body,
            :excerpt, :meta_title, :meta_description, :author_id, :reason,
            :evidence_hash, :created_at
        )
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ContentRevision
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByContentAndLocale(string $contentId, string $locale): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_AND_LOCALE, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function getLatestRevisionNumber(string $contentId, string $locale): int
    {
        $result = $this->connection->query(self::SQL_LATEST_REVISION_NUMBER, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);

        return $result->first()?->getInt('latest') ?? 0;
    }

    public function save(ContentRevision $revision): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $revision->id,
            'content_id' => $revision->contentId,
            'locale' => $revision->locale,
            'revision_number' => $revision->revisionNumber,
            'title' => $revision->title,
            'slug' => $revision->slug,
            'body' => $revision->body,
            'excerpt' => $revision->excerpt,
            'meta_title' => $revision->metaTitle,
            'meta_description' => $revision->metaDescription,
            'author_id' => $revision->authorId,
            'reason' => $revision->reason,
            'evidence_hash' => $revision->evidenceHash,
            'created_at' => $revision->createdAt->format('c'),
        ]);
    }

    private static function hydrate(Row $row): ContentRevision
    {
        return new ContentRevision(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            locale: $row->getString('locale'),
            revisionNumber: $row->getInt('revision_number'),
            title: $row->getString('title'),
            slug: $row->getString('slug'),
            body: $row->getString('body'),
            excerpt: $row->getNullableString('excerpt'),
            metaTitle: $row->getNullableString('meta_title'),
            metaDescription: $row->getNullableString('meta_description'),
            authorId: $row->getString('author_id'),
            reason: $row->getNullableString('reason'),
            evidenceHash: $row->getString('evidence_hash'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
