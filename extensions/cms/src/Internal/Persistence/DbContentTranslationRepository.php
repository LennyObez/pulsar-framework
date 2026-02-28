<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\InListBuilder;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

use function count;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use ContentTranslationRepositoryInterface for public API')]
final readonly class DbContentTranslationRepository implements ContentTranslationRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_content_translations WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_CONTENT_ID = <<<'SQL'
        SELECT * FROM cms_content_translations
        WHERE content_id = :content_id
        ORDER BY locale
        SQL;

    private const string SQL_FIND_BY_CONTENT_AND_LOCALE = <<<'SQL'
        SELECT * FROM cms_content_translations
        WHERE content_id = :content_id AND locale = :locale
        SQL;

    private const string SQL_FIND_BY_PATH = <<<'SQL'
        SELECT * FROM cms_content_translations
        WHERE locale = :locale
            AND path = :path
            AND tenant_key = COALESCE(:tenant_id, '00000000-0000-0000-0000-000000000000')
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'content_id', 'locale', 'tenant_id', 'title', 'slug_segment', 'path',
        'body', 'excerpt', 'meta_title', 'meta_description', 'og_image_id',
        'robots', 'structured_data_overrides', 'reading_time_minutes',
        'body_plaintext', 'headings_text', 'custom_fields_text', 'taxonomy_terms_text',
    ];

    private const array UPSERT_UPDATE = [
        'title', 'slug_segment', 'path', 'body', 'excerpt', 'meta_title',
        'meta_description', 'og_image_id', 'robots', 'structured_data_overrides',
        'reading_time_minutes', 'body_plaintext', 'headings_text',
        'custom_fields_text', 'taxonomy_terms_text',
    ];

    // SQL_FIND_BY_CONTENT_IDS built dynamically via InListBuilder for portability

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM cms_content_translations WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ContentTranslation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByContentId(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_ID, [
            'content_id' => $contentId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByContentIds(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $inClause = InListBuilder::compile($this->connection->driver(), 'content_id', 'content_ids', count($contentIds));
        $sql = "SELECT * FROM cms_content_translations WHERE $inClause ORDER BY content_id, locale";
        $bindings = InListBuilder::expandParams($this->connection->driver(), 'content_ids', $contentIds);

        $result = $this->connection->query($sql, $bindings);

        $grouped = [];

        foreach ($result->rows as $row) {
            $translation = self::hydrate($row);
            $grouped[$translation->contentId][] = $translation;
        }

        return $grouped;
    }

    public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_AND_LOCALE, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_PATH, [
            'locale' => $locale,
            'path' => $path,
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function save(ContentTranslation $translation): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_content_translations',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $translation->id,
            'content_id' => $translation->contentId,
            'locale' => $translation->locale,
            'tenant_id' => $this->tenantId,
            'title' => $translation->title,
            'slug_segment' => $translation->slugSegment,
            'path' => $translation->path,
            'body' => $translation->body,
            'excerpt' => $translation->excerpt,
            'meta_title' => $translation->metaTitle,
            'meta_description' => $translation->metaDescription,
            'og_image_id' => $translation->ogImageId,
            'robots' => $translation->robots,
            'structured_data_overrides' => $translation->structuredDataOverrides !== null
                ? json_encode($translation->structuredDataOverrides, JSON_THROW_ON_ERROR)
                : null,
            'reading_time_minutes' => $translation->readingTimeMinutes,
            'body_plaintext' => $translation->bodyPlaintext,
            'headings_text' => $translation->headingsText,
            'custom_fields_text' => $translation->customFieldsText,
            'taxonomy_terms_text' => $translation->taxonomyTermsText,
        ]);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    private static function hydrate(Row $row): ContentTranslation
    {
        $structuredDataRaw = $row->getNullableString('structured_data_overrides');

        return new ContentTranslation(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            locale: $row->getString('locale'),
            title: $row->getString('title'),
            slugSegment: $row->getString('slug_segment'),
            path: $row->getString('path'),
            body: $row->getString('body'),
            excerpt: $row->getNullableString('excerpt'),
            metaTitle: $row->getNullableString('meta_title'),
            metaDescription: $row->getNullableString('meta_description'),
            ogImageId: $row->getNullableString('og_image_id'),
            robots: $row->getNullableString('robots'),
            structuredDataOverrides: $structuredDataRaw !== null
                ? json_decode($structuredDataRaw, true, flags: JSON_THROW_ON_ERROR)
                : null,
            readingTimeMinutes: $row->getNullableInt('reading_time_minutes'),
            bodyPlaintext: $row->getString('body_plaintext'),
            headingsText: $row->getString('headings_text'),
            customFieldsText: $row->getString('custom_fields_text'),
            taxonomyTermsText: $row->getString('taxonomy_terms_text'),
        );
    }
}
