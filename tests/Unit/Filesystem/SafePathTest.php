<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafePath;
use Symfony\Component\Filesystem\Filesystem;

use function is_dir;
use function mkdir;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(SafePath::class)]
final class SafePathTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . sprintf('pulsar_sp_%s', uniqid('', true));

        if (!mkdir($base, 0o750, true) && !is_dir($base)) {
            self::markTestSkipped('Could not create temp directory for SafePath test.');
        }

        $this->root = $base;
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            new Filesystem()->remove($this->root);
        }
    }

    #[Test]
    public function resolveUnderCwdAcceptsSimpleRelativePath(): void
    {
        $path = SafePath::resolveUnderCwd('src');

        self::assertNotNull($path);
        self::assertStringEndsWith(DIRECTORY_SEPARATOR . 'src', $path->absolute);
    }

    #[Test]
    public function resolveUnderCwdRejectsParentTraversal(): void
    {
        self::assertNull(SafePath::resolveUnderCwd('../etc/passwd'));
        self::assertNull(SafePath::resolveUnderCwd('foo/../../etc'));
        self::assertNull(SafePath::resolveUnderCwd('..'));
    }

    #[Test]
    public function resolveUnderCwdRejectsAbsoluteUnixPath(): void
    {
        self::assertNull(SafePath::resolveUnderCwd('/etc/passwd'));
    }

    #[Test]
    public function resolveUnderCwdRejectsAbsoluteWindowsPath(): void
    {
        self::assertNull(SafePath::resolveUnderCwd('C:\\Windows\\System32'));
        self::assertNull(SafePath::resolveUnderCwd('D:/foo'));
    }

    #[Test]
    public function resolveUnderCwdRejectsNulByte(): void
    {
        self::assertNull(SafePath::resolveUnderCwd("src\0../etc"));
    }

    #[Test]
    public function resolveUnderCwdRejectsEmptyString(): void
    {
        self::assertNull(SafePath::resolveUnderCwd(''));
    }

    #[Test]
    public function childRejectsTraversalSegments(): void
    {
        $base = SafePath::resolveUnderCwd('src');
        self::assertNotNull($base);

        self::assertNull($base->child('..'));
        self::assertNull($base->child('.'));
        self::assertNull($base->child(''));
        self::assertNull($base->child('foo/bar'));
        self::assertNull($base->child('foo\\bar'));
        self::assertNull($base->child("foo\0bar"));
    }

    #[Test]
    public function childAcceptsSingleComponent(): void
    {
        $base = SafePath::resolveUnderCwd('src');
        self::assertNotNull($base);

        $child = $base->child('Filesystem');

        self::assertNotNull($child);
        self::assertStringEndsWith(
            'src' . DIRECTORY_SEPARATOR . 'Filesystem',
            $child->absolute,
        );
    }

    #[Test]
    public function toStringReturnsAbsolutePath(): void
    {
        $path = SafePath::resolveUnderCwd('src');
        self::assertNotNull($path);

        self::assertSame($path->absolute, (string) $path);
    }

    #[Test]
    public function resolveUnderHonoursCustomBoundary(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);

        $under = SafePath::resolveUnder('src', $cwd);
        $outside = SafePath::resolveUnder('../etc', $cwd);

        self::assertNotNull($under);
        self::assertNull($outside);
    }

    #[Test]
    public function adoptExistingRejectsSiblingSharingBoundaryNamePrefix(): void
    {
        // Trust boundary = <root>/app ; sibling = <root>/appdata.
        // A naive str_starts_with('<root>/appdata', '<root>/app')
        // would mistakenly accept the sibling as in-boundary.
        $boundaryDir = $this->root . DIRECTORY_SEPARATOR . 'app';
        $siblingDir = $this->root . DIRECTORY_SEPARATOR . 'appdata';
        self::assertTrue(mkdir($boundaryDir, 0o750));
        self::assertTrue(mkdir($siblingDir, 0o750));
        self::assertTrue(mkdir($boundaryDir . DIRECTORY_SEPARATOR . 'inside', 0o750));

        // Build a SafePath whose trust boundary IS <root>/app (the
        // boundary field is the realpath of the directory passed to
        // resolveUnder).
        $boundary = SafePath::resolveUnder('inside', $boundaryDir);
        self::assertNotNull($boundary);
        self::assertSame(realpath($boundaryDir), $boundary->boundary);

        self::assertNull(SafePath::adoptExisting($siblingDir, $boundary));
        self::assertNotNull(SafePath::adoptExisting($boundaryDir, $boundary));
        self::assertNotNull(
            SafePath::adoptExisting($boundaryDir . DIRECTORY_SEPARATOR . 'inside', $boundary),
        );
    }

    #[Test]
    public function resolveUnderRejectsPathUnderExistingFileComponent(): void
    {
        // A regular file cannot host a child directory, so a candidate
        // of <file>/sub must be rejected rather than walked past.
        $file = $this->root . DIRECTORY_SEPARATOR . 'leaf.txt';
        file_put_contents($file, 'x');

        self::assertNull(SafePath::resolveUnder('leaf.txt/sub', $this->root));
    }
}
