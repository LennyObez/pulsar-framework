<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\DocumentedStructureScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;

use function implode;
use function in_array;

/**
 * The repository-structure ratchet, watched refusing.
 *
 * `RepositoryStructureTest` is pointed at this checkout, where the page and the tree now
 * agree — so the only answer it will ever give here is the empty list, and an empty list
 * is also what a scan that reads nothing returns. The two are indistinguishable until
 * somebody builds the drifted case, which is what this does.
 *
 * All three defects are real ones this page carried. It documented a `bootstrap/` whose
 * PHP had moved to `tools/php/bootstrap.php`; it never mentioned `benchmarks/`, `public/`
 * or `vendor-bin/` after they were added; and it pointed at paths that had moved. The
 * fixture reproduces each in a repository of its own, under the per-test temp directory,
 * because a defect planted in this tree would be visible to every other worker scanning
 * it and its failure would be attributed to whatever test happened to be running.
 *
 * The fixture is a real git repository rather than a plain directory on purpose. The
 * ratchet asks git what is *tracked*, not the filesystem what is present, so a fixture
 * that only wrote files would exercise a different rule than the one that ships.
 */
#[CoversClass(DocumentedStructureScanner::class)]
#[GuardsGate(
    gate: 'RepositoryStructureTest::everyTrackedTopLevelDirectoryIsDocumented',
    plants: 'a tracked top-level directory the fixture page draws no entry for',
)]
#[GuardsGate(
    gate: 'RepositoryStructureTest::everyDocumentedDirectoryExists',
    plants: 'a tree-block entry for a directory the fixture repository does not track',
)]
#[GuardsGate(
    gate: 'RepositoryStructureTest::everyPathThePageNamesResolves',
    plants: 'a backticked path the fixture repository neither tracks nor has, one it has but gitignores, and a directory it has that tracks nothing',
)]
final class RepositoryStructureRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /**
     * A structure page describing the fixture repository correctly.
     *
     * Two directories, both tracked; two backticked paths, both real. Every refusal below
     * is this page with exactly one thing wrong with it.
     */
    private const string HEALTHY_PAGE = <<<'MARKDOWN'
        # Repository structure

        ```
        .
        ├─ src/              # Framework core
        └─ tools/            # Tooling configuration
        ```

        The bootstrap the runner loads is `tools/php/bootstrap.php`, and the module tree
        starts at `src/Routing/Router.php`.
        MARKDOWN;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->plant($this->tempDirectory, 'src/Routing/Router.php', "<?php\n");
        $this->plant($this->tempDirectory, 'tools/php/bootstrap.php', "<?php\n");

        // Ignored, and present. A page naming this looks correct to the only person who
        // can see it — the author — and points every reader at nothing.
        $this->plant($this->tempDirectory, '.gitignore', "var/\n");
        $this->plant($this->tempDirectory, 'var/cache/keep.txt', "generated\n");
    }

    #[Test]
    public function itRefusesATrackedDirectoryThePageDoesNotDraw(): void
    {
        // The defect: a directory added to the repository, and a page nobody updated.
        // benchmarks/, public/ and vendor-bin/ all arrived this way.
        $this->plant($this->tempDirectory, 'benchmarks/Orm/QueryBuilderBench.php', "<?php\n");

        $tracked = $this->trackedDirectoriesOfFixture();
        $documented = DocumentedStructureScanner::documentedDirectories(self::HEALTHY_PAGE);
        $undocumented = DocumentedStructureScanner::undocumented($tracked, $documented);

        self::assertContains(
            'benchmarks',
            $undocumented,
            'The structure ratchet stayed silent on a tracked top-level directory the page '
            . 'never mentions. What ships when it stays silent is the page this repository '
            . 'actually had: a newcomer told the tree has fourteen top-level directories when '
            . 'it has sixteen, with no way to discover the other two except by looking. '
            . 'It reported: [' . implode(', ', $undocumented) . ']',
        );
    }

    #[Test]
    public function itRefusesADrawnDirectoryNothingTracks(): void
    {
        // The defect in the other direction, and the more misleading one: the page kept
        // drawing bootstrap/ long after the only PHP in it had moved to tools/php/.
        $page = <<<'MARKDOWN'
            ```
            .
            ├─ bootstrap/        # Deterministic startup logic
            ├─ src/              # Framework core
            └─ tools/            # Tooling configuration
            ```

            The bootstrap is `tools/php/bootstrap.php`.
            MARKDOWN;

        $tracked = $this->trackedDirectoriesOfFixture();
        $documented = DocumentedStructureScanner::documentedDirectories($page);
        $absent = DocumentedStructureScanner::absent($tracked, $documented);

        self::assertContains(
            'bootstrap',
            $absent,
            'The ratchet stayed silent on a directory the page draws and the repository does '
            . 'not have. What ships when it stays silent is a reader looking for a directory '
            . 'that is not there and having no way to tell whether it was removed, renamed, or '
            . 'never existed at all. It reported: [' . implode(', ', $absent) . ']',
        );
    }

    #[Test]
    public function itRefusesAPathNoCloneWouldHave(): void
    {
        $page = self::HEALTHY_PAGE . "\n\nStartup lives in `bootstrap/bootstrap.php`.\n";

        $referenced = DocumentedStructureScanner::referencedPaths($page);
        $missing = $this->scannerOfFixture()->missing($referenced);

        self::assertContains(
            'bootstrap/bootstrap.php',
            $missing,
            'The ratchet stayed silent on a path the page names and the repository does not '
            . 'have. What ships when it stays silent is exactly what shipped: a page telling '
            . 'every new contributor to open bootstrap/bootstrap.php, which has not existed '
            . 'for as long as anyone can remember. It reported: [' . implode(', ', $missing) . ']',
        );
    }

    #[Test]
    public function itRefusesAPathThatOnlyTheAuthorCanSee(): void
    {
        // The half that a "does this file exist" check cannot catch. The file is right
        // there in the working copy; it is in .gitignore, so no clone has it.
        $page = self::HEALTHY_PAGE . "\n\nGenerated output collects in `var/cache/keep.txt`.\n";

        $referenced = DocumentedStructureScanner::referencedPaths($page);
        $missing = $this->scannerOfFixture()->missing($referenced);

        self::assertContains(
            'var/cache/keep.txt',
            $missing,
            'The ratchet stayed silent on a page pointing readers into a gitignored path. '
            . 'What ships when it stays silent is a documented location that exists for one '
            . 'person and nobody else — which is how three pages here came to promise the '
            . 'external security audit memo under a directory .gitignore excludes from every '
            . 'clone. It reported: [' . implode(', ', $missing) . ']',
        );
    }

    #[Test]
    public function itRefusesADirectoryThatExistsAndHoldsNothingTracked(): void
    {
        // The subtler version of the same defect, and the one that slipped through the
        // first draft of this rule. `var/` is not itself gitignored — only what is inside
        // it is — so it is present, unignored, and still absent from every clone, because
        // git tracks a directory exactly when it tracks something in it. This repository
        // has one: bootstrap/, holding nothing but a generated cache.
        $page = self::HEALTHY_PAGE . "\n\nGenerated output collects under `var/cache/`.\n";

        $referenced = DocumentedStructureScanner::referencedPaths($page);
        $missing = $this->scannerOfFixture()->missing($referenced);

        self::assertContains(
            'var/cache',
            $missing,
            'The ratchet accepted a directory that exists here and in no clone. What ships '
            . 'when it stays silent is a structure page describing directories a reader will '
            . 'not find after cloning — indistinguishable, from their side, from a page that '
            . 'is simply out of date, and impossible for the author to notice because every '
            . 'one of them is right there. It reported: [' . implode(', ', $missing) . ']',
        );
    }

    /**
     * The healthy case, asserted only so the five above cannot pass by accident.
     *
     * If the scan returned everything, or read nothing out of the page, every refusal
     * above would still read as a refusal.
     */
    #[Test]
    public function itIsSilentOnTheSameRepositoryWithoutTheDefect(): void
    {
        $tracked = $this->trackedDirectoriesOfFixture();
        $documented = DocumentedStructureScanner::documentedDirectories(self::HEALTHY_PAGE);

        self::assertSame(['src', 'tools'], $documented, 'the tree block was not read as written');
        self::assertSame([], DocumentedStructureScanner::undocumented($tracked, $documented));
        self::assertSame([], DocumentedStructureScanner::absent($tracked, $documented));

        $referenced = DocumentedStructureScanner::referencedPaths(self::HEALTHY_PAGE);

        self::assertSame(
            ['src/Routing/Router.php', 'tools/php/bootstrap.php'],
            $referenced,
            'the page\'s backticked paths were not read as paths, so the refusals above would '
            . 'have been about a list this test constructed rather than about the page',
        );
        self::assertSame([], $this->scannerOfFixture()->missing($referenced));
        self::assertFalse(
            in_array('var', $tracked, true),
            'the fixture tracks its gitignored directory, so "no clone would have this" would '
            . 'have meant something other than what the ratchet claims',
        );
    }

    /**
     * @return list<string>
     */
    private function trackedDirectoriesOfFixture(): array
    {
        $tracked = $this->scannerOfFixture()->trackedTopLevelDirectories();

        self::assertNotNull($tracked, 'the fixture repository was built but git would not read it');

        return $tracked;
    }

    private function scannerOfFixture(): DocumentedStructureScanner
    {
        if (!$this->initialiseRepository($this->tempDirectory)) {
            self::markTestSkipped('git cannot be driven here, so the fixture repository cannot be built');
        }

        return new DocumentedStructureScanner($this->tempDirectory);
    }
}
