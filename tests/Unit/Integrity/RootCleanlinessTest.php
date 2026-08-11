<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function escapeshellarg;
use function exec;
use function sort;
use function str_contains;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * The repository root has a finite, knowable content.
 *
 * Manifests, licence, tool configuration, entry documentation. Everything else is an
 * anomaly: a one-off script, a scratch file, a generated artefact that was committed
 * once and then belonged to nobody.
 *
 * Such a file enters because `git add` has no opinion about it, and the boundary then
 * holds only as long as whoever commits is paying attention. Attention drifts; this
 * does not.
 *
 * Only *tracked* files are considered. Generated artefacts (preload dumps, caches) appear
 * at the root legitimately during development and are gitignored — failing on those would
 * make the guard fire on a clean checkout doing normal work, which is how a guard earns
 * being switched off.
 */
final class RootCleanlinessTest extends TestCase
{
    /** Where a shell sends output it should not keep. */
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /**
     * Everything permitted at the repository root, with why it is there.
     *
     * Adding an entry is a deliberate act. If you find yourself adding one to make this
     * test pass, the question to answer first is whether the file belongs in a
     * subdirectory instead — scripts/ for tooling, docs/ for documentation, tools/ for
     * build configuration that is not read from the root by convention.
     *
     * @var list<string>
     */
    private const array ALLOWED = [
        // Package manifests and locks
        'composer.json',
        'composer.lock',
        'package.json',
        'pnpm-lock.yaml',

        // Entry documentation — anything longer belongs in docs/
        'CHANGELOG.md',
        'LICENSE',
        'NOTICE',
        'README.md',
        'ROADMAP.md',

        // Toolchain pins: the single source of truth every workflow reads
        '.nvmrc',
        '.php-version',

        // Tool configuration that its tool only reads from the root
        '.dockerignore',
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
        '.php-cs-fixer.dist.php',
        '.prettierignore',
        '.prettierrc.json',
        '.semgrep.yml',
        '.size-limit-ignore',
        'eslint.config.js',
        'infection.json5',
        'tsconfig.json',
        'vitest.config.ts',

        // Environment templates. The real .env files are gitignored; these two are
        // deliberately tracked and deliberately editable.
        '.env.example',
        '.env.production.example',
    ];

    #[Test]
    public function onlyExpectedFilesAreTrackedAtTheRoot(): void
    {
        $tracked = $this->trackedRootFiles();

        self::assertNotSame([], $tracked, 'no tracked root files found — the check would pass vacuously');

        $unexpected = array_values(array_diff($tracked, self::ALLOWED));

        self::assertSame(
            [],
            $unexpected,
            "Unexpected files tracked at the repository root:\n  "
            . implode("\n  ", $unexpected)
            . "\n\nA root file is either infrastructure the whole repository reads, or it is "
            . 'in the wrong place. Move it to scripts/, docs/ or tools/ — or, if it genuinely '
            . 'belongs here, add it to ALLOWED with a comment saying why.',
        );
    }

    /**
     * The allowlist must not outlive what it allows.
     *
     * An entry for a file that no longer exists is the same rot in the other direction:
     * it grants permission nobody needs and hides that the list was never revisited.
     */
    #[Test]
    public function theAllowlistHasNoStaleEntries(): void
    {
        $tracked = $this->trackedRootFiles();
        $stale = array_values(array_diff(self::ALLOWED, $tracked));

        self::assertSame(
            [],
            $stale,
            "ALLOWED permits files that are no longer tracked at the root:\n  "
            . implode("\n  ", $stale),
        );
    }

    /**
     * @return list<string>
     */
    private function trackedRootFiles(): array
    {
        $root = dirname(__DIR__, 3);

        // git is the authority on what is *committed*, which is the thing being guarded.
        // Reading the directory would also see generated artefacts and local scratch
        // files, and failing on those would make the guard fire during ordinary work.
        //
        // Stderr goes to the null device, not into the list. Merged with `2>&1` it arrived
        // as data — `fatal: not a git repository` counted as a root file, and the guard
        // reported it as one, in every checkout without a repository.
        exec(
            'git -C ' . escapeshellarg($root) . ' ls-files --full-name 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        if ($status !== 0) {
            self::markTestSkipped('git cannot read this checkout, so tracked files cannot be listed');
        }

        $files = array_values(array_filter(
            $output,
            static fn(string $line): bool => $line !== '' && !str_contains($line, '/'),
        ));

        $files = array_map(trim(...), $files);
        sort($files);

        return $files;
    }
}
