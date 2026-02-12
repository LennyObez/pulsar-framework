<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use JsonException;
use Pulsar\Api\Internal;

use function array_map;
use function explode;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function stripslashes;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

/**
 * Parses Markdown with YAML frontmatter into CMS content data arrays.
 *
 * Supports single and multi-document Markdown files. Multi-document files
 * use `---` as a document separator (on its own line between documents).
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class MarkdownImporter
{
    /**
     * Parse a single Markdown document with YAML frontmatter.
     *
     * @return array{content: array<string, mixed>, translation: array<string, mixed>, blocks: list<array<string, mixed>>}
     * @throws JsonException If block data JSON is malformed.
     */
    public function parse(string $markdown): array
    {
        $markdown = trim($markdown);

        $frontmatter = [];
        $body = $markdown;

        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $markdown, $matches) === 1) {
            $frontmatter = $this->parseFrontmatter($matches[1]);
            $body = trim($matches[2]);
        }

        $blocks = [];
        $bodyContent = $body;

        if (preg_match_all('/<!-- block:(\w+):(\d+) -->\n(.+?)(?=\n<!-- block:|\z)/s', $body, $blockMatches, PREG_SET_ORDER) > 0) {
            // Extract body before the first block marker
            $firstBlockPos = strpos($body, '<!-- block:');

            if ($firstBlockPos !== false && $firstBlockPos > 0) {
                $bodyContent = trim(substr($body, 0, $firstBlockPos));
            } elseif ($firstBlockPos === 0) {
                $bodyContent = '';
            }

            foreach ($blockMatches as $match) {
                $blocks[] = [
                    'block_type' => $match[1],
                    'sort_order' => (int) $match[2],
                    'data' => json_decode(trim($match[3]), true, 512, JSON_THROW_ON_ERROR),
                ];
            }
        }

        return [
            'content' => [
                'content_type' => $frontmatter['content_type'] ?? 'page',
                'status' => $frontmatter['status'] ?? 'draft',
                'author_id' => $frontmatter['author_id'] ?? 'system',
                'created_at' => $frontmatter['created_at'] ?? null,
                'published_at' => $frontmatter['published_at'] ?? null,
            ],
            'translation' => [
                'title' => $frontmatter['title'] ?? '',
                'slug' => $frontmatter['slug'] ?? '',
                'path' => $frontmatter['path'] ?? $frontmatter['slug'] ?? '',
                'locale' => $frontmatter['locale'] ?? 'en',
                'body' => $bodyContent,
                'excerpt' => $frontmatter['excerpt'] ?? null,
                'meta_title' => $frontmatter['meta_title'] ?? null,
                'meta_description' => $frontmatter['meta_description'] ?? null,
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * Parse a multi-document Markdown file separated by `---` markers.
     *
     * @return list<array{content: array<string, mixed>, translation: array<string, mixed>, blocks: list<array<string, mixed>>}>
     * @throws JsonException If block data JSON is malformed.
     */
    public function parseAll(string $markdown): array
    {
        $documents = $this->splitDocuments($markdown);

        return array_map(
            fn(string $doc): array => $this->parse($doc),
            $documents,
        );
    }

    /**
     * Split a multi-document Markdown string into individual documents.
     *
     * Documents are separated by `---` on its own line. The tricky part is that
     * `---` is also used for frontmatter boundaries, so we track state to
     * distinguish separator lines from frontmatter delimiters.
     *
     * @return list<string>
     */
    private function splitDocuments(string $markdown): array
    {
        $lines = preg_split('/\r?\n/', $markdown);

        if ($lines === false) {
            return [trim($markdown)];
        }

        $documents = [];
        $current = [];
        $inFrontmatter = false;
        $seenFrontmatterEnd = false;

        foreach ($lines as $line) {
            if ($line === '---') {
                if (!$inFrontmatter && !$seenFrontmatterEnd && $current === []) {
                    // Start of frontmatter for a new document
                    $inFrontmatter = true;
                    $current[] = $line;
                } elseif ($inFrontmatter) {
                    // End of frontmatter
                    $inFrontmatter = false;
                    $seenFrontmatterEnd = true;
                    $current[] = $line;
                } elseif ($seenFrontmatterEnd) {
                    // Document separator — save current and start new
                    $documents[] = trim(implode("\n", $current));
                    $current = [];
                    $inFrontmatter = false;
                    $seenFrontmatterEnd = false;
                } else {
                    $current[] = $line;
                }
            } else {
                $current[] = $line;
            }
        }

        if ($current !== []) {
            $doc = trim(implode("\n", $current));

            if ($doc !== '') {
                $documents[] = $doc;
            }
        }

        return $documents;
    }

    /**
     * Parse YAML-like frontmatter key: value pairs.
     *
     * @return array<string, string>
     */
    private function parseFrontmatter(string $raw): array
    {
        $result = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));

            if ($value === '') {
                continue;
            }

            // Unquote double-quoted values
            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                $value = stripslashes(substr($value, 1, -1));
            }

            // Unquote single-quoted values
            if (strlen($value) >= 2 && $value[0] === "'" && $value[-1] === "'") {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
