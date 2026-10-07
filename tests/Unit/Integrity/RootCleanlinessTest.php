<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\RootFileScanner;

use function dirname;
use function implode;

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
        'pnpm-workspace.yaml', // pnpm 12 reads its settings only from here

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

        $unexpected = RootFileScanner::unexpected($tracked, self::ALLOWED);

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
        $stale = RootFileScanner::stale($tracked, self::ALLOWED);

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
        // The rule itself lives in RootFileScanner, which takes the root it reads. That
        // is what lets RootCleanlinessRefusesTest hand it a repository with a stray file
        // in it and observe the refusal, instead of only ever seeing this healthy one.
        $tracked = new RootFileScanner(dirname(__DIR__, 3))->trackedRootFiles();

        if ($tracked === null) {
            self::markTestSkipped('git cannot read this checkout, so tracked files cannot be listed');
        }

        return $tracked;
    }
}
