<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\ProjectSourceRoots;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The project's source roots must come from its composer PSR-4 map, not an
 * assumed `src/` layout: a project mapping "App\\": "app/" has no src/ directory,
 * which made `pulsar optimize` / cache warm / preload throw or silently scan
 * nothing.
 */
#[CoversClass(ProjectSourceRoots::class)]
final class ProjectSourceRootsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_roots_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    #[Test]
    public function itResolvesANonSrcPsr4Root(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0o750, true);
        $this->writeComposer('{"autoload": {"psr-4": {"App\\\\": "app/"}}}');

        self::assertSame(
            [$this->root . DIRECTORY_SEPARATOR . 'app'],
            ProjectSourceRoots::discover($this->root),
        );
    }

    #[Test]
    public function itUnionsEveryMappedRootIncludingArrayForm(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0o750, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'lib', 0o750, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'domain', 0o750, true);

        // A PSR-4 prefix may map to a single path or to a list of paths.
        $this->writeComposer(
            '{"autoload": {"psr-4": {"App\\\\": ["app/", "lib/"], "Domain\\\\": "domain/"}}}',
        );

        $roots = ProjectSourceRoots::discover($this->root);

        self::assertContains($this->root . DIRECTORY_SEPARATOR . 'app', $roots);
        self::assertContains($this->root . DIRECTORY_SEPARATOR . 'lib', $roots);
        self::assertContains($this->root . DIRECTORY_SEPARATOR . 'domain', $roots);
        self::assertCount(3, $roots);
    }

    #[Test]
    public function itSkipsADeclaredButMissingRootInsteadOfFailing(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0o750, true);
        // "Missing\\" points at a directory that does not exist on disk.
        $this->writeComposer('{"autoload": {"psr-4": {"App\\\\": "app/", "Missing\\\\": "nope/"}}}');

        self::assertSame(
            [$this->root . DIRECTORY_SEPARATOR . 'app'],
            ProjectSourceRoots::discover($this->root),
        );
    }

    #[Test]
    public function itIgnoresARootThatEscapesTheProject(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0o750, true);
        $this->writeComposer('{"autoload": {"psr-4": {"App\\\\": "app/", "Evil\\\\": "../../etc"}}}');

        self::assertSame(
            [$this->root . DIRECTORY_SEPARATOR . 'app'],
            ProjectSourceRoots::discover($this->root),
        );
    }

    #[Test]
    public function itFallsBackToSrcWhenComposerJsonIsAbsent(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'src', 0o750, true);

        self::assertSame(
            [$this->root . DIRECTORY_SEPARATOR . 'src'],
            ProjectSourceRoots::discover($this->root),
        );
    }

    #[Test]
    public function itFallsBackToSrcWhenComposerJsonIsMalformed(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'src', 0o750, true);
        $this->writeComposer('{ not json');

        self::assertSame(
            [$this->root . DIRECTORY_SEPARATOR . 'src'],
            ProjectSourceRoots::discover($this->root),
        );
    }

    #[Test]
    public function itReturnsNothingWhenNoRootExists(): void
    {
        // No composer.json and no src/: callers must skip, never fail.
        self::assertSame([], ProjectSourceRoots::discover($this->root));
    }

    private function writeComposer(string $json): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'composer.json', $json);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
