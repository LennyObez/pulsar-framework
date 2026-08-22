<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

use function count;

/**
 * Provides previous/next navigation links for documentation pages.
 *
 * Resolves adjacent doc pages by their sort order within the same section,
 * enabling sequential reading through documentation.
 *
 * @psalm-api Resolved by the docs controller from the DI container;
 *            not new'd by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DocNavigationService
{
    private const string SQL_ORDERED_DOCS = <<<'SQL'
        SELECT
            c.id,
            ct.title,
            ct.path,
            fv_section.value_string AS section,
            COALESCE(fv_order.value_int, 0) AS sort_order
        FROM cms_contents c
        JOIN cms_content_translations ct
            ON ct.content_id = c.id AND ct.locale = :locale
        LEFT JOIN cms_content_type_fields ftf_section
            ON ftf_section.content_type = 'doc_page' AND ftf_section.field_key = 'section'
        LEFT JOIN cms_content_field_values fv_section
            ON fv_section.content_id = c.id AND fv_section.field_id = ftf_section.id
        LEFT JOIN cms_content_type_fields ftf_order
            ON ftf_order.content_type = 'doc_page' AND ftf_order.field_key = 'order'
        LEFT JOIN cms_content_field_values fv_order
            ON fv_order.content_id = c.id AND fv_order.field_id = ftf_order.id
        WHERE c.content_type = 'doc_page'
          AND c.deleted_at IS NULL
          AND ct.status = 'published'
        ORDER BY fv_section.value_string ASC, COALESCE(fv_order.value_int, 0) ASC
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Get the previous and next doc pages relative to the given content ID.
     *
     * @return array{prev: ?array{title: string, path: string}, next: ?array{title: string, path: string}}
     */
    public function getNavigation(string $contentId, string $locale): array
    {
        $result = $this->connection->query(self::SQL_ORDERED_DOCS, [
            'locale' => $locale,
        ]);

        /** @var list<array{id: string, title: string, path: string}> $docs */
        $docs = [];
        $currentIndex = null;

        foreach ($result->rows as $row) {
            $id = $row->getString('id');
            $docs[] = [
                'id' => $id,
                'title' => $row->getString('title'),
                'path' => $row->getString('path'),
            ];

            if ($id === $contentId) {
                $currentIndex = count($docs) - 1;
            }
        }

        if ($currentIndex === null) {
            return ['prev' => null, 'next' => null];
        }

        $prev = $currentIndex > 0
            ? ['title' => (string) $docs[$currentIndex - 1]['title'], 'path' => (string) $docs[$currentIndex - 1]['path']]
            : null;

        $next = $currentIndex < count($docs) - 1
            ? ['title' => (string) $docs[$currentIndex + 1]['title'], 'path' => (string) $docs[$currentIndex + 1]['path']]
            : null;

        return ['prev' => $prev, 'next' => $next];
    }
}
