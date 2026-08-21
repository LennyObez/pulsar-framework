<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\RootFileScanner;

use function implode;
use function in_array;

/**
 * The root-cleanliness ratchet, watched refusing.
 *
 * RootCleanlinessTest has only ever been pointed at a clean repository, so the only
 * answer anyone has seen from it is the empty list — and an empty list is what a broken
 * scan returns too. The two are indistinguishable until somebody builds the dirty case,
 * which is what this does: a repository whose root carries a committed scratch file that
 * the allowlist does not name.
 *
 * The fixture is a real git repository under the per-test temp directory, not this
 * checkout. The ratchet asks git rather than the filesystem on purpose — tracked, not
 * present — so a fixture that only wrote a file would exercise a different rule than the
 * one that ships, and would still pass if the scan were changed to read the directory.
 */
#[CoversClass(RootFileScanner::class)]
#[GuardsGate(
    gate: 'RootCleanlinessTest::onlyExpectedFilesAreTrackedAtTheRoot',
    plants: 'a scratch file committed at the root of a fixture repository, absent from the allowlist',
)]
#[GuardsGate(
    gate: 'RootCleanlinessTest::theAllowlistHasNoStaleEntries',
    plants: 'an allowlist entry naming a file the fixture repository does not track',
)]
final class RootCleanlinessRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** What the fixture repository is allowed to carry at its root. */
    private const array ALLOWED = ['README.md', 'composer.json'];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->plant($this->tempDirectory, 'README.md', "# fixture\n");
        $this->plant($this->tempDirectory, 'composer.json', "{}\n");

        // A subdirectory file, so the "root only" filter is exercised rather than
        // assumed. If the filter broke, this would surface as an unexpected root file
        // and the assertions below would pass for the wrong reason.
        $this->plant($this->tempDirectory, 'src/Thing.php', "<?php\n");
    }

    #[Test]
    public function itRefusesARootFileTheAllowlistDoesNotName(): void
    {
        // The defect: a one-off script committed at the root because `git add` had no
        // opinion about it. This is the shape the ratchet was written for.
        $this->plant($this->tempDirectory, 'fix-things-quickly.php', "<?php\n");

        $tracked = $this->trackedRootFilesOfFixture();
        $unexpected = RootFileScanner::unexpected($tracked, self::ALLOWED);

        self::assertContains(
            'fix-things-quickly.php',
            $unexpected,
            'The root-cleanliness ratchet stayed silent on a scratch file committed at the '
            . 'repository root. What ships when it stays silent is a root that accumulates '
            . 'one-off scripts, generated artefacts and abandoned experiments that belong to '
            . 'nobody — the exact drift the allowlist exists to make visible in review. '
            . 'It reported: [' . implode(', ', $unexpected) . ']',
        );
    }

    #[Test]
    public function itRefusesAnAllowlistEntryNothingTracksAnyMore(): void
    {
        // The defect in the other direction: permission for a file that is gone.
        $allowedWithGhost = [...self::ALLOWED, 'DELETED-LONG-AGO.md'];

        $tracked = $this->trackedRootFilesOfFixture();
        $stale = RootFileScanner::stale($tracked, $allowedWithGhost);

        self::assertContains(
            'DELETED-LONG-AGO.md',
            $stale,
            'The ratchet stayed silent on an allowlist entry no file answers to. What ships '
            . 'when it stays silent is an allowlist that grows monotonically and stops being '
            . 'read — every stale entry is standing permission for a filename anyone can '
            . 'reintroduce without review. It reported: [' . implode(', ', $stale) . ']',
        );
    }

    /**
     * The healthy case, asserted only so the two above cannot pass by accident.
     *
     * If the scan returned everything, or nothing, both refusals above would still read
     * as refusals. This is the control that says the fixture is otherwise clean.
     */
    #[Test]
    public function itIsSilentOnTheSameRepositoryWithoutTheDefect(): void
    {
        $tracked = $this->trackedRootFilesOfFixture();

        self::assertSame([], RootFileScanner::unexpected($tracked, self::ALLOWED));
        self::assertSame([], RootFileScanner::stale($tracked, self::ALLOWED));
        self::assertFalse(
            in_array('src/Thing.php', $tracked, true),
            'the scan reported a file below the root, so "unexpected at the root" would '
            . 'have meant something other than what the ratchet claims',
        );
    }

    /**
     * @return list<string>
     */
    private function trackedRootFilesOfFixture(): array
    {
        if (!$this->initialiseRepository($this->tempDirectory)) {
            self::markTestSkipped('git cannot be driven here, so the fixture repository cannot be built');
        }

        $tracked = new RootFileScanner($this->tempDirectory)->trackedRootFiles();

        self::assertNotNull($tracked, 'the fixture repository was built but git would not read it');

        return $tracked;
    }
}
