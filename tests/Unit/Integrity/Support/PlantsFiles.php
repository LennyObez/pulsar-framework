<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use FilesystemIterator;
use Override;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function chmod;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Builds the unhealthy repository a negative test needs, somewhere it cannot do harm.
 *
 * The defect has to be planted somewhere for the gate to be observed refusing it, and
 * the one place it must never be planted is the repository under test: a verification
 * artefact left behind in a shared tree is the incident this whole effort exists to
 * prevent, and it has happened here before. So every fixture is written under the
 * per-test temporary directory that `FilesystemTestCase` creates and removes, and the
 * gate is pointed at that root instead of at this checkout.
 *
 * That also makes the fixtures safe under a parallel runner. A defect planted in the
 * real tree would be visible to every other worker scanning it, and the failure it
 * produced would be attributed to whatever test happened to be running.
 *
 * PHPStan reads @psalm-require-extends as the same tag, so stating it twice is an error
 * rather than belt and braces; one declaration binds both analysers.
 *
 * @phpstan-require-extends \Pulsar\Tests\Support\FilesystemTestCase
 */
trait PlantsFiles
{
    /** Where a shell sends output it should not keep. */
    private const string PLANT_NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /**
     * Hands the fixture tree back to the cleanup that removes it.
     *
     * git writes its loose objects read-only, on every platform. On Windows a read-only
     * file cannot be unlinked at all, so `FilesystemTestCase` deleted everything it could
     * and left `.git` behind — 53 abandoned fixture repositories in the system temp
     * directory before this was noticed, one per run of the two ratchets that need a real
     * repository to answer about.
     *
     * That is precisely the artefact this whole exercise exists to stop being left
     * behind, produced by the tests written to stop it. Restoring write permission before
     * the base class walks the tree costs one pass and removes the failure mode rather
     * than the symptom.
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->restoreWritePermissions($this->tempDirectory);

        parent::tearDown();
    }

    /**
     * Writes one file into the fixture tree, creating its directories.
     */
    protected function plant(string $root, string $relativePath, string $contents): string
    {
        $path = $root . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            Assert::fail('could not create the fixture directory ' . $directory);
        }

        if (file_put_contents($path, $contents) === false) {
            Assert::fail('could not write the fixture file ' . $path);
        }

        return $path;
    }

    /**
     * Makes the fixture tree a real git repository and stages everything in it.
     *
     * Two of the ratchets ask git rather than the filesystem — deliberately, because
     * git is the authority on what is committed and the filesystem is not. Testing them
     * therefore means giving them something git can answer about, not stubbing git out:
     * a stub would prove the parsing and leave untested the thing that was wrong before,
     * which is which files git reports in the first place.
     *
     * @return bool false when git cannot be driven here, so the caller can skip rather
     *              than report a green it did not earn
     */
    protected function initialiseRepository(string $root): bool
    {
        $quoted = escapeshellarg($root);
        $output = [];

        exec('git -C ' . $quoted . ' init -q 2>' . self::PLANT_NULL_DEVICE, $output, $status);

        if ($status !== 0) {
            return false;
        }

        exec('git -C ' . $quoted . ' add -A 2>' . self::PLANT_NULL_DEVICE, $output, $status);

        return $status === 0;
    }

    /**
     * Clears the read-only bit from every entry beneath a fixture root.
     *
     * Children before parents, so a directory is still traversable when its contents are
     * being adjusted. A path that has already gone is not an error — the base class may
     * have removed part of the tree on an earlier attempt.
     */
    private function restoreWritePermissions(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir()) {
                if (is_dir($path)) {
                    chmod($path, 0o700);
                }

                continue;
            }

            if (is_file($path)) {
                chmod($path, 0o600);
            }
        }
    }
}
