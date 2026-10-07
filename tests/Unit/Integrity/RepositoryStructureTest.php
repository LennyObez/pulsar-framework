<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\DocumentedStructureScanner;

use function dirname;
use function file_get_contents;
use function implode;

use const DIRECTORY_SEPARATOR;

/**
 * `docs/repository-structure.md` must describe this repository.
 *
 * It is the page a newcomer opens first and the page `docs/architecture.md` delegates the
 * whole subject to, so when it is wrong there is no second opinion anywhere. It had been
 * wrong for months in every way a hand-transcribed tree can be: a `bootstrap/` entry
 * holding a file that does not exist, no `benchmarks/`, no `public/`, no `vendor-bin/`,
 * and counts against `src/`, `extensions/` and `config/` that had drifted by 24, 14 and 14.
 *
 * Nothing failed, because nothing was checking. Prose has no failure mode; it reads as
 * true right up until a reader follows it somewhere and finds nothing there.
 *
 * The counts are gone rather than pinned — a number that must be edited whenever a module
 * is added is a gate that fires on ordinary work, and a gate that fires on ordinary work
 * gets switched off. What is pinned is what a reader actually acts on: the directory set,
 * and every path the page tells them to look at.
 */
final class RepositoryStructureTest extends TestCase
{
    #[Test]
    public function everyTrackedTopLevelDirectoryIsDocumented(): void
    {
        $tracked = $this->trackedTopLevelDirectories();
        $documented = DocumentedStructureScanner::documentedDirectories($this->page());

        self::assertNotSame(
            [],
            $documented,
            'no directories were read out of the tree block. Every assertion here would then '
            . 'be comparing against an empty list, which is the failure mode this whole file '
            . 'exists to refuse one level up — has the tree block been reformatted?',
        );

        $undocumented = DocumentedStructureScanner::undocumented($tracked, $documented);

        self::assertSame(
            [],
            $undocumented,
            "docs/repository-structure.md does not mention these tracked top-level directories:\n  "
            . implode("\n  ", $undocumented)
            . "\n\nThis is the direction the page failed in before: benchmarks/, public/ and\n"
            . "vendor-bin/ were all added and none of them reached the page, so a reader was\n"
            . 'told the repository has a shape it stopped having. Add an entry saying what the '
            . 'directory is for.',
        );
    }

    #[Test]
    public function everyDocumentedDirectoryExists(): void
    {
        $tracked = $this->trackedTopLevelDirectories();
        $documented = DocumentedStructureScanner::documentedDirectories($this->page());
        $absent = DocumentedStructureScanner::absent($tracked, $documented);

        self::assertSame(
            [],
            $absent,
            "docs/repository-structure.md draws directories this repository does not track:\n  "
            . implode("\n  ", $absent)
            . "\n\nThe other half of the drift, and the more misleading half: a reader looking for\n"
            . "one of these finds nothing and has no way to tell whether it was removed, renamed,\n"
            . 'or never existed. The page said `bootstrap/` for months after the only PHP in it '
            . 'had moved to tools/php/bootstrap.php.',
        );
    }

    /**
     * Every path the page points at must be one a clone will contain.
     *
     * A directory list can be right while every path in the prose around it is wrong, and
     * the prose is what a reader follows. Tracked, or present and not ignored — the second
     * clause is what catches a page directing readers into the gitignored audit scratch
     * area, which no clone of this repository has ever had.
     */
    #[Test]
    public function everyPathThePageNamesResolves(): void
    {
        $referenced = DocumentedStructureScanner::referencedPaths($this->page());

        self::assertNotSame(
            [],
            $referenced,
            'the page names no repository paths at all, so this assertion would pass over an '
            . 'empty list rather than over the page.',
        );

        $missing = $this->scanner()->missing($referenced);

        self::assertSame(
            [],
            $missing,
            "docs/repository-structure.md names paths a clone of this repository would not have:\n  "
            . implode("\n  ", $missing)
            . "\n\nEither the path moved and the page did not, or the page points into something\n"
            . "gitignored. Both send a reader looking for a file that is not there; the second is\n"
            . 'worse, because it is there in the author\'s working copy and so looks correct to '
            . 'the only person who can check it.',
        );
    }

    /**
     * @return list<string>
     */
    private function trackedTopLevelDirectories(): array
    {
        $tracked = $this->scanner()->trackedTopLevelDirectories();

        if ($tracked === null) {
            self::markTestSkipped('git cannot read this checkout, so tracked files cannot be listed');
        }

        return $tracked;
    }

    private function scanner(): DocumentedStructureScanner
    {
        return new DocumentedStructureScanner(dirname(__DIR__, 3));
    }

    private function page(): string
    {
        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'repository-structure.md';

        $contents = file_get_contents($path);

        self::assertIsString($contents, 'docs/repository-structure.md is missing from ' . $path);

        return $contents;
    }
}
