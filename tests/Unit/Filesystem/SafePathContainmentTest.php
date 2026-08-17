<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafePath;

use function bin2hex;
use function is_link;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Containment is the property WritablePathGuard rests on, and it had no tests of its
 * own — only the guard's, which exercise it through one caller.
 *
 * Two things went unverified as a result. The `..` folding was written to fix a
 * fail-open on POSIX and was only ever checked through a single path shape. And the
 * symlink resistance the class advertises was inherited from a docblock: nothing
 * created a symlink and watched it be refused.
 */
#[CoversClass(SafePath::class)]
final class SafePathContainmentTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        // realpath() first. On Windows sys_get_temp_dir() can return an 8.3 short form
        // (C:\Users\RUNNER~1\...), and SafePath treats a short-name segment as
        // undecidable and leans to "contained" on purpose. Every verdict below would
        // then be true, testing that escape hatch instead of the containment rule.
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        $this->base = $temp . DIRECTORY_SEPARATOR . 'pulsar_spc_' . bin2hex(random_bytes(8));
        mkdir($this->base . DIRECTORY_SEPARATOR . 'boundary', 0o750, true);
        mkdir($this->base . DIRECTORY_SEPARATOR . 'outside', 0o750, true);
        mkdir($this->base . DIRECTORY_SEPARATOR . 'boundary_sibling', 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (['link', 'link_dir'] as $name) {
            $path = $this->base . DIRECTORY_SEPARATOR . 'boundary' . DIRECTORY_SEPARATOR . $name;

            if (is_link($path)) {
                // A directory symlink on Windows needs rmdir(), a file symlink unlink().
                @unlink($path) || @rmdir($path);
            }
        }

        foreach (['boundary', 'outside', 'boundary_sibling'] as $dir) {
            @rmdir($this->base . DIRECTORY_SEPARATOR . $dir);
        }

        @rmdir($this->base);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function traversalCases(): iterable
    {
        yield 'plain descendant' => ['child', true, 'the ordinary case'];
        yield 'the boundary itself' => ['', true, 'a boundary contains itself'];
        yield 'single dot is inert' => ['./child', true, '. resolves to the same directory'];
        yield 'redundant dots' => ['child/./grandchild', true, 'interior . segments collapse'];
        yield 'up then back in' => ['child/../other', true, 'still lands inside'];
        yield 'up and out' => ['../outside', false, 'one level out must be refused'];
        yield 'up twice and out' => ['child/../../outside', false, 'depth does not launder it'];
        yield 'backslash separator' => ['child\\..\\other', true, 'a Windows-shaped path folds the same way'];
        yield 'far above the root' => ['../../../../../../etc', false, 'cannot climb past the root into another tree'];

        // The trap a raw prefix comparison falls into: the sibling's name starts with
        // the boundary's, so str_starts_with() would call it contained.
        yield 'prefix sibling' => ['../boundary_sibling/x', false, 'a name-prefix sibling is outside'];
    }

    #[Test]
    #[DataProvider('traversalCases')]
    public function traversalIsFoldedBeforeItIsJudged(string $relative, bool $expected, string $why): void
    {
        $boundary = $this->base . DIRECTORY_SEPARATOR . 'boundary';
        $candidate = $relative === '' ? $boundary : $boundary . DIRECTORY_SEPARATOR . $relative;

        self::assertSame($expected, SafePath::isWithin($candidate, $boundary), $why);
    }

    /**
     * Every case above, with the boundary absent — the state a container binding the
     * webroot after boot is in. The verdicts must not change: this branch decides
     * textually, and folding is exactly what makes that safe for `..` and for prefix
     * siblings.
     */
    #[Test]
    #[DataProvider('traversalCases')]
    public function theSameVerdictsHoldWhenTheBoundaryDoesNotExist(
        string $relative,
        bool $expected,
        string $why,
    ): void {
        $boundary = $this->base . DIRECTORY_SEPARATOR . 'not_created_yet';
        $candidate = $relative === '' ? $boundary : $boundary . DIRECTORY_SEPARATOR . $relative;

        self::assertSame($expected, SafePath::isWithin($candidate, $boundary), $why);
    }

    /**
     * The claim the class has always made and nothing checked: a symlink inside the
     * boundary that points out of it does not smuggle a path past the check.
     *
     * Folding `..` textually cannot catch this — the path contains no `..` at all — so
     * this is what the realpath() step is for, and it is the reason the resolved-boundary
     * branch is stronger than the textual one.
     */
    #[Test]
    public function aSymlinkLeavingTheBoundaryIsRefused(): void
    {
        $boundary = $this->base . DIRECTORY_SEPARATOR . 'boundary';
        $outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        $link = $boundary . DIRECTORY_SEPARATOR . 'link_dir';

        if (!@symlink($outside, $link)) {
            // Unprivileged Windows cannot create symlinks without Developer Mode.
            self::markTestSkipped('symlink() unavailable on ' . PHP_OS_FAMILY . ' for this user');
        }

        self::assertFalse(
            SafePath::isWithin($link, $boundary),
            'the link itself resolves outside, so it is not contained',
        );

        self::assertFalse(
            SafePath::isWithin($link . DIRECTORY_SEPARATOR . 'payload', $boundary),
            'and neither is a path that would be created through it',
        );
    }

    /**
     * A NUL byte must be refused, not thrown over.
     *
     * resolveUnder() rejects NUL and returns null, but isWithin() let it reach
     * realpath(), which raises a ValueError in PHP 8 — so the same malformed input that
     * one entry point declines cleanly took the other down with an uncaught exception,
     * during boot, where the configured path is read.
     */
    #[Test]
    public function aNulByteIsRefusedRatherThanThrown(): void
    {
        $boundary = $this->base . DIRECTORY_SEPARATOR . 'boundary';

        self::assertFalse(SafePath::isWithin("a\0b", $boundary), 'a NUL path is inside nothing');
        self::assertFalse(
            SafePath::isWithin($boundary . DIRECTORY_SEPARATOR . "child\0evil", $boundary),
            'including one that would otherwise look contained',
        );
        self::assertFalse(SafePath::isWithin($boundary, "b\0oundary"), 'and a NUL boundary decides nothing');
    }

    /**
     * An 8.3 short name is an alias only the filesystem can resolve.
     *
     * With the boundary resolvable, realpath() canonicalises it and containment holds.
     * With the boundary absent there is nothing to canonicalise against, so
     * `…\PUBLIC~1\cache` failed to match a boundary written in long form — and false
     * means "outside", which WritablePathGuard acts on as permission to proceed. The
     * same fail-open the `..` folding closed, reached through a different alias.
     *
     * Undecidable now leans to contained, so the guard refuses rather than allows.
     */
    #[Test]
    public function anEightDotThreeAliasIsNotAllowedToDecideContainmentTextually(): void
    {
        $absent = $this->base . DIRECTORY_SEPARATOR . 'Program Files';

        self::assertTrue(
            SafePath::isWithin($this->base . DIRECTORY_SEPARATOR . 'PROGRA~1' . DIRECTORY_SEPARATOR . 'cache', $absent),
            'a candidate naming a short segment cannot be proved outside, so it is treated as inside',
        );

        self::assertTrue(
            SafePath::isWithin($absent . DIRECTORY_SEPARATOR . 'cache', $this->base . DIRECTORY_SEPARATOR . 'PROGRA~1'),
            'and the same holds when the boundary is the one written short',
        );
    }

    /**
     * The conservative reading must not swallow ordinary names.
     */
    #[Test]
    public function anOrdinaryNameContainingATildeIsStillJudgedNormally(): void
    {
        $absent = $this->base . DIRECTORY_SEPARATOR . 'not_created_yet';

        self::assertFalse(
            SafePath::isWithin($this->base . DIRECTORY_SEPARATOR . 'elsewhere~backup', $absent),
            'a tilde alone is not a short name: no digits follow it',
        );
        self::assertFalse(
            SafePath::isWithin($this->base . DIRECTORY_SEPARATOR . 'a-long-directory~1x', $absent),
            'nor is a name whose tilde is not followed only by digits',
        );
    }

    /**
     * A symlink that stays inside must not be punished for being a symlink.
     */
    #[Test]
    public function aSymlinkStayingInsideTheBoundaryIsAccepted(): void
    {
        $boundary = $this->base . DIRECTORY_SEPARATOR . 'boundary';
        $target = $boundary . DIRECTORY_SEPARATOR . 'child';
        mkdir($target, 0o750, true);
        $link = $boundary . DIRECTORY_SEPARATOR . 'link_dir';

        if (!@symlink($target, $link)) {
            @rmdir($target);
            self::markTestSkipped('symlink() unavailable on ' . PHP_OS_FAMILY . ' for this user');
        }

        try {
            self::assertTrue(SafePath::isWithin($link, $boundary));
        } finally {
            @unlink($link) || @rmdir($link);
            @rmdir($target);
        }
    }
}
