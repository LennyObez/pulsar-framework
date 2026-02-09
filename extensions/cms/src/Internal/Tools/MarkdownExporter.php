<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentTranslation;

use function array_map;
use function implode;
use function json_encode;
use function str_contains;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Exports CMS content items as Markdown with YAML frontmatter.
 *
 * Each content item becomes a Markdown document with metadata in the frontmatter
 * header and the body content as Markdown text. Content blocks are appended as
 * sections delimited by HTML comments.
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class MarkdownExporter
{
    /**
     * Export a single content item with its translation and blocks.
     *
     * @param list<ContentBlock> $blocks Ordered content blocks
     */
    public function export(Content $content, ContentTranslation $translation, array $blocks = []): string
    {
        $lines = ["---\n"];
        $lines[] = $this->buildFrontmatter($content, $translation);
        $lines[] = "---\n\n";
        $lines[] = $translation->body;

        if ($blocks !== []) {
            $lines[] = "\n\n";
            $lines[] = $this->formatBlocks($blocks);
        }

        return implode('', $lines);
    }

    /**
     * Export multiple content items as a concatenated Markdown document.
     *
     * Items are separated by a standalone `---` line (YAML document separator).
     *
     * @param list<array{content: Content, translation: ContentTranslation, blocks: list<ContentBlock>}> $items
     */
    public function exportAll(array $items): string
    {
        $documents = array_map(
            fn(array $item): string => $this->export(
                $item['content'],
                $item['translation'],
                $item['blocks'],
            ),
            $items,
        );

        return implode("\n---\n", $documents);
    }

    private function buildFrontmatter(Content $content, ContentTranslation $translation): string
    {
        $fields = [
            'title' => $translation->title,
            'slug' => $translation->slugSegment,
            'path' => $translation->path,
            'content_type' => $content->contentType->value,
            'status' => $content->status->value,
            'author_id' => $content->authorId,
            'locale' => $translation->locale,
            'created_at' => $content->createdAt->format('c'),
            'published_at' => $content->publishedAt?->format('c'),
            'meta_title' => $translation->metaTitle,
            'meta_description' => $translation->metaDescription,
            'excerpt' => $translation->excerpt,
        ];

        $lines = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            $lines[] = $key . ': ' . $this->escapeYamlValue($value);
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeYamlValue(string $value): string
    {
        if (
            str_contains($value, ':')
            || str_contains($value, '#')
            || str_contains($value, '"')
            || str_contains($value, "'")
            || str_contains($value, "\n")
            || str_contains($value, '[')
            || str_contains($value, ']')
            || str_contains($value, '{')
            || str_contains($value, '}')
        ) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    /**
     * @param list<ContentBlock> $blocks
     */
    private function formatBlocks(array $blocks): string
    {
        $sections = [];

        foreach ($blocks as $block) {
            $header = "<!-- block:{$block->blockType}:{$block->sortOrder} -->";
            $body = json_encode($block->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $sections[] = $header . "\n" . $body;
        }

        return implode("\n\n", $sections);
    }
}
