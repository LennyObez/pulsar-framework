<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;

/**
 * Generates a structured sidebar navigation tree from published doc pages.
 *
 * Groups doc pages by their "section" custom field and sorts within
 * each section by the "order" custom field value.
 */
#[Api(since: '1.0.0')]
final readonly class DocSidebarGenerator
{
    private const string SQL_DOC_PAGES = <<<'SQL'
        SELECT
            ct.title,
            ct.slug_segment AS slug,
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
     * Build a sidebar structure from published doc_page content items.
     *
     * @return list<array{section: string, items: list<array{title: string, slug: string, path: string, order: int}>}>
     */
    public function generate(string $locale, ?string $version = null): array
    {
        $params = ['locale' => $locale];

        $sql = self::SQL_DOC_PAGES;

        if ($version !== null) {
            $sql = <<<'SQL'
                SELECT
                    ct.title,
                    ct.slug_segment AS slug,
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
                LEFT JOIN cms_content_type_fields ftf_version
                    ON ftf_version.content_type = 'doc_page' AND ftf_version.field_key = 'version'
                LEFT JOIN cms_content_field_values fv_version
                    ON fv_version.content_id = c.id AND fv_version.field_id = ftf_version.id
                WHERE c.content_type = 'doc_page'
                  AND c.deleted_at IS NULL
                  AND ct.status = 'published'
                  AND fv_version.value_string = :version
                ORDER BY fv_section.value_string ASC, COALESCE(fv_order.value_int, 0) ASC
                SQL;

            $params['version'] = $version;
        }

        $result = $this->connection->query($sql, $params);

        /** @var array<string, list<array{title: string, slug: string, path: string, order: int}>> $grouped */
        $grouped = [];

        foreach ($result->map(static fn(Row $row): array => [
            'title' => $row->getString('title'),
            'slug' => $row->getString('slug'),
            'path' => $row->getString('path'),
            'section' => $row->getNullableString('section') ?? 'General',
            'order' => $row->getInt('sort_order'),
        ]) as $item) {
            $section = $item['section'];
            unset($item['section']);
            $grouped[$section][] = $item;
        }

        /** @var list<array{section: string, items: list<array{title: string, slug: string, path: string, order: int}>}> $sidebar */
        $sidebar = [];

        foreach ($grouped as $section => $items) {
            $sidebar[] = ['section' => $section, 'items' => $items];
        }

        return $sidebar;
    }
}
