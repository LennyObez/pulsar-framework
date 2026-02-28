<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Showcase;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Renders showcase/portfolio project grids from CMS content.
 *
 * Queries published showcase_project content items with their
 * custom field values and returns structured data for rendering.
 */
#[Api(since: '1.0.0')]
final readonly class ShowcaseGridRenderer
{
    private const string SQL_PROJECTS = <<<'SQL'
        SELECT
            c.id,
            ct.title,
            ct.slug_segment AS slug,
            ct.path
        FROM cms_contents c
        JOIN cms_content_translations ct
            ON ct.content_id = c.id AND ct.locale = :locale
        WHERE c.content_type = 'showcase_project'
          AND c.deleted_at IS NULL
          AND ct.status = 'published'
        ORDER BY c.created_at DESC
        SQL;

    private const string SQL_FIELD_VALUES = <<<'SQL'
        SELECT ftf.field_key, fv.value_string, fv.value_json, fv.value_bool, fv.value_int
        FROM cms_content_field_values fv
        JOIN cms_content_type_fields ftf ON ftf.id = fv.field_id
        WHERE fv.content_id = :content_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Get showcase projects with their custom field values.
     *
     * @return list<array{title: string, slug: string, path: string, project_url: ?string, screenshot: ?string, industry: ?string, technologies: list<string>, company: ?string, is_featured: bool}>
     */
    public function getProjects(
        string $locale,
        ?string $industry = null,
        bool $featuredOnly = false,
        int $limit = 20,
    ): array {
        $result = $this->connection->query(self::SQL_PROJECTS . " LIMIT $limit", [
            'locale' => $locale,
        ]);

        /** @var list<array{title: string, slug: string, path: string, project_url: ?string, screenshot: ?string, industry: ?string, technologies: list<string>, company: ?string, is_featured: bool}> $projects */
        $projects = [];

        foreach ($result->map(static fn(Row $row): array => [
            'id' => $row->getString('id'),
            'title' => $row->getString('title'),
            'slug' => $row->getString('slug'),
            'path' => $row->getString('path'),
        ]) as $project) {
            $fields = $this->loadFieldValues($project['id']);

            /** @var list<string> $technologies */
            $technologies = $fields['technologies'] ?? [];

            $entry = [
                'title' => $project['title'],
                'slug' => $project['slug'],
                'path' => $project['path'],
                'project_url' => isset($fields['project_url']) && is_string($fields['project_url']) ? $fields['project_url'] : null,
                'screenshot' => isset($fields['screenshot']) && is_string($fields['screenshot']) ? $fields['screenshot'] : null,
                'industry' => isset($fields['industry']) && is_string($fields['industry']) ? $fields['industry'] : null,
                'technologies' => $technologies,
                'company' => isset($fields['company']) && is_string($fields['company']) ? $fields['company'] : null,
                'is_featured' => ($fields['is_featured'] ?? false) === true,
            ];

            if ($industry !== null && $entry['industry'] !== $industry) {
                continue;
            }

            if ($featuredOnly && !$entry['is_featured']) {
                continue;
            }

            $projects[] = $entry;
        }

        return $projects;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFieldValues(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_FIELD_VALUES, [
            'content_id' => $contentId,
        ]);

        /** @var array<string, mixed> $values */
        $values = [];

        foreach ($result->map(static fn(Row $r): array => [
            'key' => $r->getString('field_key'),
            'value_string' => $r->getNullableString('value_string'),
            'value_json' => $r->getNullableString('value_json'),
            'value_bool' => $r->getNullableString('value_bool'),
        ]) as $field) {
            $json = $field['value_json'];

            if (is_string($json) && $json !== '') {
                $values[$field['key']] = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($field['value_bool'] !== null) {
                $values[$field['key']] = $field['value_bool'] === '1' || $field['value_bool'] === 'true';
            } elseif ($field['value_string'] !== null) {
                $values[$field['key']] = $field['value_string'];
            }
        }

        return $values;
    }
}
