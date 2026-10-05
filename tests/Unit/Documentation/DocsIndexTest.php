<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Documentation\Support\TrackedFiles;

use function array_count_values;
use function array_map;
use function dirname;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_string;
use function preg_match_all;
use function preg_replace;
use function sort;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * A page nobody links to is a page nobody reads.
 *
 * Twenty-six pages under `docs/` had no inbound reference from anywhere in this
 * repository — not another page, not the README, not a source comment. They were not
 * drafts; they were finished documents reachable only by guessing the filename, which
 * for a documentation tree is the same failure as not having written them. The
 * structural cause was that `docs/` had no index at all, so nothing about adding a page
 * ever required connecting it to anything.
 *
 * `docs/README.md` is that index, and these cases are what keep it one. The expensive
 * half is `everyDocumentationPageIsListedInTheIndex`: it enumerates the tree rather than
 * reading a list, so a page added next month is in scope the moment it is written, and
 * the build refuses it until someone has said, in one line, what it is for.
 *
 * Link resolution is checked over every page, not only the index, because the same rot
 * produced the other half of the problem — `docs/codegen.md` pointed at
 * `control-packs.md` for months after the file was renamed, and nothing noticed.
 *
 * Code spans and fenced blocks are stripped before links are read, because markdown does
 * not render a link inside them and neither should this: a page quoting a broken link as
 * evidence must not have to falsify the quote to stay green.
 *
 * The corpus is what git ships ({@see TrackedFiles}), so a gitignored local page can neither
 * pass the index here nor be linked from it.
 */
#[CoversNothing]
final class DocsIndexTest extends TestCase
{
    private const string INDEX = 'docs/README.md';

    /**
     * The index must reach every page. Enumerated, never listed.
     */
    #[Test]
    public function everyDocumentationPageIsListedInTheIndex(): void
    {
        $linked = [];

        foreach ($this->indexTargets() as $target) {
            $linked[strtolower($target)] = true;
        }

        $unlisted = [];

        foreach ($this->documentationPages() as $page) {
            if (!isset($linked[strtolower($page)])) {
                $unlisted[] = $page;
            }
        }

        sort($unlisted);

        self::assertSame(
            [],
            $unlisted,
            'These pages exist under docs/ but nothing in docs/README.md links to them, so a reader '
            . 'can only reach them by knowing the filename: ' . implode(', ', $unlisted),
        );
    }

    /**
     * An index entry pointing at nothing is worse than a missing entry.
     */
    #[Test]
    public function everyPageTheIndexListsExists(): void
    {
        $root = $this->repositoryRoot();
        $missing = [];

        foreach ($this->indexTargets() as $target) {
            if (!file_exists($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $target)) {
                $missing[] = $target;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/README.md lists pages that do not exist: ' . implode(', ', $missing),
        );
    }

    /**
     * One page, one entry. A page listed under two headings has no home.
     */
    /** A link to a gitignored page resolves on the disk that has it and nowhere else. */
    #[Test]
    public function theIndexLinksNoPageGitIgnores(): void
    {
        self::assertSame(
            [],
            TrackedFiles::ignored($this->repositoryRoot(), array_map(static fn(string $t): string => 'docs/' . $t, $this->indexTargets())),
            'docs/README.md links pages .gitignore keeps out of every clone',
        );
    }

    #[Test]
    public function noPageIsListedTwiceInTheIndex(): void
    {
        $counts = array_count_values(array_map(strtolower(...), $this->indexTargets()));
        $duplicates = [];

        foreach ($counts as $target => $count) {
            if ($count > 1) {
                $duplicates[] = (string) $target;
            }
        }

        self::assertSame(
            [],
            $duplicates,
            'These pages are listed more than once in docs/README.md: ' . implode(', ', $duplicates),
        );
    }

    /**
     * A bare link is a filename. The point of the index is the sentence beside it.
     */
    #[Test]
    public function everyIndexEntryCarriesADescription(): void
    {
        preg_match_all(
            '/^- \[[^\]]+\]\(([^)\s]+)\)(.*)$/m',
            $this->strippedIndex(),
            $matches,
            PREG_SET_ORDER,
        );

        self::assertNotSame([], $matches, 'The index has no entries; its format changed under this test');

        $bare = [];

        foreach ($matches as $entry) {
            if (trim(str_replace('—', '', $entry[2])) === '') {
                $bare[] = $entry[1];
            }
        }

        self::assertSame(
            [],
            $bare,
            'These index entries are a link and nothing else: ' . implode(', ', $bare),
        );
    }

    /**
     * Every relative link on every page, not only the index.
     */
    #[Test]
    public function everyRelativeLinkInEveryDocumentationPageResolves(): void
    {
        $root = $this->repositoryRoot();
        $broken = [];

        foreach ($this->allMarkdownFiles() as $page) {
            $directory = dirname($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page));
            $markdown = $this->read($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page));

            preg_match_all('/\]\(([^)\s]+)\)/', $this->stripCode($markdown), $matches);

            foreach ($matches[1] as $target) {
                if ($this->isExternal($target)) {
                    continue;
                }

                $path = $this->stripFragment($target);

                if ($path === '') {
                    continue;
                }

                if (!file_exists($directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                    $broken[] = $page . ' -> ' . $target;
                }
            }
        }

        sort($broken);

        self::assertSame(
            [],
            $broken,
            'These documentation links resolve to nothing: ' . implode('; ', $broken),
        );
    }

    /**
     * Every `docs/`-relative target the index links, in order.
     *
     * @return list<string>
     */
    private function indexTargets(): array
    {
        preg_match_all('/^- \[[^\]]+\]\(([^)\s]+)\)/m', $this->strippedIndex(), $matches);

        self::assertNotSame([], $matches[1], 'The index lists no pages; its format changed under this test');

        $targets = [];

        foreach ($matches[1] as $target) {
            if ($this->isExternal($target)) {
                continue;
            }

            $path = $this->stripFragment($target);

            if ($path !== '') {
                $targets[] = $path;
            }
        }

        return $targets;
    }

    /**
     * Every page the index is responsible for: markdown under `docs/`, minus the
     * decision records (which carry their own index) and the index itself.
     *
     * Paths are `docs/`-relative and use forward slashes.
     *
     * @return list<string>
     */
    private function documentationPages(): array
    {
        $pages = [];

        foreach ($this->allMarkdownFiles() as $page) {
            if (str_starts_with($page, 'docs/adr/')) {
                continue;
            }

            $relative = substr($page, 5);

            if (strtolower($relative) === 'readme.md') {
                continue;
            }

            $pages[] = $relative;
        }

        return $pages;
    }

    /**
     * Every markdown file under `docs/`, as repository-relative forward-slash paths.
     *
     * @return list<string>
     */
    private function allMarkdownFiles(): array
    {
        $files = TrackedFiles::under($this->repositoryRoot(), '.md', 'docs');

        self::assertNotSame([], $files, 'No markdown was found under docs/');

        return $files;
    }

    /**
     * The index with fenced blocks and code spans removed.
     */
    private function strippedIndex(): string
    {
        $path = $this->repositoryRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::INDEX);

        self::assertFileExists(
            $path,
            'docs/ has no index. Every page under it is then reachable only by knowing its filename',
        );

        return $this->stripCode($this->read($path));
    }

    /**
     * Remove fenced blocks and inline code spans.
     *
     * Markdown renders no link inside either, so neither is a navigation claim: a fence
     * holds examples, and a code span is how the audit records quote a link that was
     * already broken when they recorded it.
     */
    private function stripCode(string $markdown): string
    {
        $withoutFences = preg_replace('/^```.*?^```/ms', '', $markdown);
        $withoutSpans = preg_replace('/`[^`\n]*`/', '', is_string($withoutFences) ? $withoutFences : $markdown);

        return is_string($withoutSpans) ? $withoutSpans : $markdown;
    }

    private function isExternal(string $target): bool
    {
        return str_starts_with($target, 'http://')
            || str_starts_with($target, 'https://')
            || str_starts_with($target, 'mailto:')
            || str_starts_with($target, '#');
    }

    private function stripFragment(string $target): string
    {
        $stripped = preg_replace('/#.*$/', '', $target);

        return is_string($stripped) ? $stripped : $target;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Could not read ' . $path);

        return $contents;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
