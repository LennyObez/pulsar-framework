<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use Pulsar\Tooling\Support\JsonDocument;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function escapeshellarg;
use function exec;
use function sort;
use function str_contains;
use function str_starts_with;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * The rule behind DriverDispatchRatchetTest, pointed at a root rather than at this one.
 *
 * The ratchet's own history is the argument for extracting it. Its search ran `git grep`
 * without `--untracked`, so a file added on a branch was invisible until it was
 * committed: green for the one person still able to act on the answer, red in CI on the
 * commit that made the file tracked. That was found by reading the flag list, not by a
 * test, because there was no way to hand the scan a repository and check what it saw —
 * the root was hard-wired to this checkout, and this checkout is healthy.
 *
 * With the root a parameter, DriverDispatchRefusesTest builds a repository containing a
 * brand-new dispatch site and watches the ratchet name it. The `--untracked` regression
 * would fail that test, which is the property the flag list could not have.
 */
final readonly class DriverDispatchScanner
{
    /** Where a shell sends output it should not keep. */
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /** The layer whose job is to know the engines. Dispatch here is the design. */
    public const string DISPATCH_LAYER = 'src/Database/';

    public function __construct(private string $root) {}

    /**
     * Every production file outside the dispatch layer that names a Driver case.
     *
     * ## Why the search reads the working tree, not the last commit
     *
     * `--untracked` is what makes this a guard rather than a record. Without it git
     * searches only files it already tracks, so a file added on a branch is invisible
     * until it is committed. The finding that produced this flag was a new
     * `src/Compliance` class dispatching on three `Driver` cases, and the suite was green
     * the whole time it existed.
     *
     * ## Why git is asked at all, rather than the directory walked
     *
     * `--untracked` keeps honouring `.gitignore`, so `node_modules` and the root
     * `vendor/` stay out of the answer. Walking the filesystem would drag them in and
     * fail the build over somebody else's code, and a guard that does that earns being
     * switched off. What is left is the set that would actually be committed: an
     * untracked, unignored `.php` file under `src/` or `extensions/` is not a false
     * positive, it is next week's debt.
     *
     * @return list<string>|null null when git cannot answer at all — no repository in
     *                           this checkout, or no git installed — which is not the
     *                           same as a clean tree and must not read as one
     */
    public function currentSites(): ?array
    {
        $output = [];

        exec(
            'git -C ' . escapeshellarg($this->root)
            . ' grep --untracked -lE "\\bDriver::(MySQL|PostgreSQL|SQLite)\\b"'
            . ' -- "src/*.php" "extensions/*.php"'
            . ' 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        // `git grep -l` exits 0 when it matched and 1 when it did not. Anything above
        // that means git could not answer.
        //
        // Stderr goes to the null device rather than into this list. Merged with `2>&1`
        // it arrived as data: `fatal: not a git repository` became a filename, the guard
        // saw a non-empty list and passed, and the ratchet reported that file as new debt.
        if ($status > 1) {
            return null;
        }

        // `.gitignore` anchors `/vendor/` at the repository root and says nothing about
        // `extensions/*/vendor/`, which a per-extension `composer install` creates. Only
        // this filter keeps such a tree out, and without it the guard would report an
        // upstream package's `match ($driver)` as debt this project can act on.
        $files = array_values(array_filter(
            $output,
            static fn(string $line): bool => $line !== ''
                && !str_starts_with($line, self::DISPATCH_LAYER)
                && !str_contains($line, '/tests/')
                && !str_contains($line, '/vendor/'),
        ));

        $files = array_map(trim(...), $files);
        sort($files);

        return $files;
    }

    /**
     * The files the baseline records, in the order the diffs below expect.
     *
     * @return list<string>
     */
    public static function baselineSites(string $baselinePath): array
    {
        $files = JsonDocument::fromFile($baselinePath)->stringList('files');
        sort($files);

        return $files;
    }

    /**
     * The number the baseline states it lists, which is the figure a reader quotes when
     * asked how much of this debt is left.
     */
    public static function declaredCount(string $baselinePath): int
    {
        return JsonDocument::fromFile($baselinePath)->int('count');
    }

    /**
     * Dispatching today and not in the baseline: debt created now.
     *
     * @param list<string> $current
     * @param list<string> $baseline
     *
     * @return list<string>
     */
    public static function unexpected(array $current, array $baseline): array
    {
        return array_values(array_diff($current, $baseline));
    }

    /**
     * In the baseline and dispatching no longer: a permission nobody is using, and a
     * remaining-debt figure that overstates itself.
     *
     * @param list<string> $current
     * @param list<string> $baseline
     *
     * @return list<string>
     */
    public static function stale(array $current, array $baseline): array
    {
        return array_values(array_diff($baseline, $current));
    }
}
