<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function escapeshellarg;
use function exec;
use function implode;
use function is_file;
use function preg_match_all;
use function rtrim;
use function sort;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * The rule behind RepositoryStructureTest, pointed at a root rather than at this one.
 *
 * `docs/repository-structure.md` described a tree that had stopped being this repository's:
 * it named a `bootstrap/` holding a PHP file that does not exist, omitted `benchmarks/`,
 * `public/` and `vendor-bin/` entirely, and put counts against `src/`, `extensions/` and
 * `config/` that were wrong by 24, 14 and 14 respectively. `docs/architecture.md` delegated
 * the whole subject to it, so the wrong answer was the only answer either page gave.
 *
 * None of that was caught by anything, because a hand-transcribed layout has no failure
 * mode — it is prose, and prose reads as true until a human notices otherwise. This makes
 * the checkable part checkable: the set of top-level directories, in both directions, and
 * every repository path the page names in backticks.
 *
 * The counts are gone rather than pinned. A count is wrong one commit after it is written
 * and right only by coincidence after that, so a gate on it would fire during ordinary
 * work — which is how a gate earns being switched off. The page names the commands that
 * produce the current inventory instead, and a command cannot drift.
 */
final readonly class DocumentedStructureScanner
{
    /** Where a shell sends output it should not keep. */
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /**
     * Backticked tokens that look like paths but are not claims about this tree.
     *
     * A shell pipeline and a namespace both survive the cheap tests; these are the
     * characters that give them away.
     *
     * @var list<string>
     */
    private const array NOT_A_PATH = [' ', "\t", '\\', '*', '|', '$', '(', ')', '<', '>', '"', "'", '='];

    public function __construct(private string $root) {}

    /**
     * Every directory git tracks directly under the root of this checkout.
     *
     * git is the authority on what a clone receives, which is the thing the page claims to
     * describe. Reading the filesystem would also see `vendor/`, `node_modules/`, build
     * output and local scratch, and demanding the page document those would make the gate
     * fire on a healthy tree.
     *
     * @return list<string>|null null when git cannot read this checkout at all, which is
     *                           not the same as a checkout that tracks nothing
     */
    public function trackedTopLevelDirectories(): ?array
    {
        $tracked = $this->trackedFiles();

        if ($tracked === null) {
            return null;
        }

        $directories = [];

        foreach ($tracked as $path) {
            $slash = strpos($path, '/');

            if ($slash !== false) {
                $directories[substr($path, 0, $slash)] = true;
            }
        }

        $names = array_keys($directories);
        sort($names);

        /** @var list<string> $names */
        return $names;
    }

    /**
     * The top-level directories the page's tree block draws.
     *
     * Only entries carrying a trailing slash count. A tree block also draws files, and a
     * file is not a claim about the directory set — reading one as a directory would make
     * the two directions below disagree for a reason that has nothing to do with drift.
     *
     * @return list<string>
     */
    public static function documentedDirectories(string $markdown): array
    {
        preg_match_all(
            '~^[^\x{251C}\x{2514}\n]*[\x{251C}\x{2514}]\x{2500}\s+([A-Za-z0-9._-]+)/~mu',
            $markdown,
            $matches,
        );

        $names = array_values(array_filter(array_map(trim(...), $matches[1])));
        sort($names);

        return $names;
    }

    /**
     * Every repository path the page names in backticks.
     *
     * A backticked token counts when it looks like a path and nothing else could explain
     * it: at least one `/`, no whitespace, no backslash (which would make it a PHP
     * namespace), no `*` (a glob is a pattern, not a path), no shell metacharacter (the
     * pipe in `git ls-files config | sort` says it is a command), and no scheme (a URL
     * refers somewhere else).
     *
     * @return list<string>
     */
    public static function referencedPaths(string $markdown): array
    {
        preg_match_all('/`([^`\n]+)`/', $markdown, $matches);

        $paths = [];

        foreach ($matches[1] as $token) {
            $token = trim($token);

            if (!self::looksLikeAPath($token)) {
                continue;
            }

            $paths[rtrim($token, '/')] = true;
        }

        $names = array_keys($paths);
        sort($names);

        /** @var list<string> $names */
        return $names;
    }

    /**
     * Tracked here and drawn by nobody: the direction the page actually failed in.
     *
     * @param list<string> $tracked
     * @param list<string> $documented
     *
     * @return list<string>
     */
    public static function undocumented(array $tracked, array $documented): array
    {
        return array_values(array_diff($tracked, $documented));
    }

    /**
     * Drawn by the page and tracked by nobody: a tree a reader cannot find.
     *
     * @param list<string> $tracked
     * @param list<string> $documented
     *
     * @return list<string>
     */
    public static function absent(array $tracked, array $documented): array
    {
        return array_values(array_diff($documented, $tracked));
    }

    /**
     * Which of these paths a clone of this checkout would not contain.
     *
     * "Contain" means tracked, or — for a file, not a directory — present and not ignored.
     *
     * Tracked alone is too strict: a page may legitimately name a file added by the same
     * change that has not been committed yet, and failing on that would make the gate fire
     * during the work it supervises.
     *
     * Present alone is too loose in two ways, and both bit. The ignored audit scratch
     * directory is present in the author's working copy, so a page pointing a reader into
     * it points at something no clone will ever have. And an untracked directory whose only
     * contents are ignored — `bootstrap/`, which holds nothing but a generated cache — is
     * present here and absent from every clone, while not itself being ignored. So the
     * tolerance is for files only: git contains a directory exactly when it tracks
     * something inside it, and there is no uncommitted-work case that needs a directory
     * named before any file in it exists.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function missing(array $paths): array
    {
        $tracked = $this->trackedFiles();

        if ($tracked === null) {
            return [];
        }

        $known = [];

        foreach ($tracked as $path) {
            $known[$path] = true;

            for ($at = strpos($path, '/'); $at !== false; $at = strpos($path, '/', $at + 1)) {
                $known[substr($path, 0, $at)] = true;
            }
        }

        $unknown = array_values(array_filter(
            $paths,
            static fn(string $path): bool => !isset($known[$path]),
        ));

        if ($unknown === []) {
            return [];
        }

        return array_values(array_filter(
            $unknown,
            fn(string $path): bool => !is_file($this->root . '/' . $path) || $this->isIgnored($path),
        ));
    }

    /**
     * A human-readable rendering of a path list, for an assertion message.
     *
     * @param list<string> $paths
     */
    public static function describe(array $paths): string
    {
        return $paths === [] ? '(none)' : implode(', ', $paths);
    }

    /**
     * Whether git would refuse to add this path.
     *
     * `check-ignore` exits 0 when the path is ignored, 1 when it is not, and 128 when it
     * cannot answer. Only 0 is a refusal; treating every non-zero status as "ignored"
     * would report every path in a checkout git cannot read.
     */
    private function isIgnored(string $path): bool
    {
        $output = [];

        exec(
            'git -C ' . escapeshellarg($this->root) . ' check-ignore -q ' . escapeshellarg($path)
            . ' 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        return $status === 0;
    }

    /**
     * @return list<string>|null
     */
    private function trackedFiles(): ?array
    {
        $output = [];

        exec(
            'git -C ' . escapeshellarg($this->root) . ' ls-files --full-name 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        if ($status !== 0) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), $output),
            static fn(string $line): bool => $line !== '',
        ));
    }

    private static function looksLikeAPath(string $token): bool
    {
        if ($token === '' || !str_contains($token, '/') || str_starts_with($token, '/')) {
            return false;
        }

        foreach (self::NOT_A_PATH as $disqualifier) {
            if (str_contains($token, $disqualifier)) {
                return false;
            }
        }

        return !str_contains($token, '://');
    }
}
