<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafePath;

#[CoversClass(SafePath::class)]
final class SafePathTest extends TestCase
{
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
}
