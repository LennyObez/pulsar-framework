<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Concurrency;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function chr;
use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function is_dir;
use function ksort;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;
use function token_get_all;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * Pins the complete set of files that touch PHP's Fiber API.
 *
 * ADR-0005 permitted Fibers in "two approved subsystems" and named them. By
 * rc.12 fifteen files under `src/` and `extensions/` used the API, in three
 * distinct roles, and nothing had failed — because nothing was looking. The
 * document went on being cited while the tree moved underneath it, which is the
 * failure mode ADR-0060 names: a rule whose violation produces no signal is
 * indistinguishable from no rule.
 *
 * Thirteen, now: the two hash chains that held a cooperative mutex with a bare
 * `Fiber::suspend()` no longer touch the API at all. This guard is what says so —
 * it refused the removal until the table was updated, which is the same service
 * it performs for an addition.
 *
 * So the inventory is asserted rather than described. A file that starts
 * creating, suspending or interrogating a Fiber fails this test with the role it
 * took on, and the author has to either put it in the table below — which means
 * updating `docs/async-model.md` and, if the rule itself moved, writing the ADR
 * that says so — or stop.
 *
 * The three roles are mechanical, deliberately: they are what the code does, not
 * what it means. What each file means is in `docs/async-model.md`, which this
 * table is the guard for.
 *
 *   - `creates`       — calls `new Fiber(...)`. Two do: the persistent runtime's
 *                       connection scheduler and the bounded FanOut primitive.
 *   - `suspends`      — calls `Fiber::suspend()`. Parking the current fiber on a
 *                       timer, and only that: a suspend whose value is not a
 *                       `FiberDelay` is a request to be woken by socket
 *                       readability, which is an answer to a different question
 *                       than any waiter here is asking.
 *   - `reads-current` — calls `Fiber::getCurrent()`. Either as the key of a
 *                       `WeakMap` holding per-execution state, or as the guard
 *                       that decides whether suspending is safe at all.
 *
 * Comments are stripped before matching. Half of these files discuss the API in
 * a docblock without calling it, and a guard that could not tell the difference
 * would fail on prose.
 *
 * @see \Pulsar\Runtime\Fiber\FiberScheduler
 * @see \Pulsar\Concurrency\FanOut
 */
#[CoversNothing]
final class FiberSurfaceInventoryTest extends TestCase
{
    /**
     * Every file under `src/` or `extensions/` that calls the Fiber API, and the
     * roles it plays. Paths are repository-relative with forward slashes.
     *
     * @var array<string, string>
     */
    private const array EXPECTED = [
        // Creates and drives fibers. These two are the schedulers; there are no
        // others, and a third would be a change to the execution model.
        'src/Concurrency/FanOut.php' => 'creates+suspends',
        'src/Runtime/Fiber/FiberScheduler.php' => 'creates',

        // Suspends the current fiber, and reads Fiber::getCurrent() first to
        // establish that there is one. Outside a fiber it falls back to the
        // sequential path.
        //
        // There is exactly one. AuditLogger and EvidenceChain were here too,
        // spinning on a bare Fiber::suspend() as a cooperative mutex over their
        // hash chains — a suspend FiberScheduler answers only when the fiber's
        // connection socket becomes readable, and which a caller outside a fiber
        // skipped altogether. Both now refuse a second entrant outright, touch no
        // part of the Fiber API, and have left this surface.
        'src/Runtime/Fiber/CooperativeSleep.php' => 'suspends+reads-current',

        // Key per-execution state by fiber identity in a WeakMap, with a stable
        // root object standing in when no fiber is active. These create nothing
        // and suspend nothing: they exist so state cannot bleed between fibers
        // created by anything at all, including a test harness.
        'extensions/studio/src/FiberScopedContextProvider.php' => 'reads-current',
        'src/Auth/AuthenticationState.php' => 'reads-current',
        'src/Auth/Authorization/Gate.php' => 'reads-current',
        'src/Context/RequestContextHolder.php' => 'reads-current',
        'src/Database/Routing/StickinessContext.php' => 'reads-current',
        'src/Http/RouteContext.php' => 'reads-current',
        'src/Security/Csrf/CsrfBindingContext.php' => 'reads-current',
        'src/Tenancy/Guard/SystemContext.php' => 'reads-current',
        'src/Tenancy/TenantContext.php' => 'reads-current',
        'src/View/Engine/ViewComposers.php' => 'reads-current',
    ];

    #[Test]
    public function theFiberSurfaceIsExactlyWhatTheAsyncModelDocuments(): void
    {
        [$actual, $scanned] = $this->scan();

        // Without this, a moved source root or a broken iterator turns the whole
        // guard into a test that passes because it looked at nothing.
        self::assertGreaterThan(
            2000,
            $scanned,
            'the scan found almost no PHP files, so its empty result proves nothing',
        );

        // The table above is grouped by role because that is what makes it
        // readable; the scan comes back sorted by path. Sorting a copy compares
        // the pairs without asserting an ordering that carries no meaning.
        $expected = self::EXPECTED;
        ksort($expected);

        self::assertSame(
            $expected,
            $actual,
            "The set of files touching PHP's Fiber API has changed.\n\n"
            . 'A new entry means a subsystem started creating, suspending or interrogating a fiber. '
            . 'That is a change to the execution model documented in docs/async-model.md and decided '
            . 'in docs/adr/0071-a-fiber-keyed-map-is-not-concurrency.md — update both, then add the '
            . "file here. A removed entry means the reverse; drop it from the table and from the doc.\n\n"
            . 'Roles: creates = new Fiber(), suspends = Fiber::suspend(), reads-current = Fiber::getCurrent().',
        );
    }

    /**
     * @return array{array<string, string>, int}
     */
    private function scan(): array
    {
        $root = str_replace(chr(92), '/', dirname(__DIR__, 3));
        $found = [];
        $scanned = 0;

        foreach (['src', 'extensions'] as $tree) {
            $dir = $root . '/' . $tree;

            if (!is_dir($dir)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace(chr(92), '/', $file->getPathname());

                // An extension's own test tree is not part of the framework's
                // fiber surface; those files exercise it on purpose.
                if (str_contains($path, '/tests/')) {
                    continue;
                }

                ++$scanned;

                $source = (string) file_get_contents($path);

                if (!str_contains($source, 'Fiber')) {
                    continue;
                }

                $roles = $this->rolesIn($source);

                if ($roles === '') {
                    continue;
                }

                $found[substr($path, strlen($root) + 1)] = $roles;
            }
        }

        ksort($found);

        return [$found, $scanned];
    }

    /**
     * The roles a single file plays, as a stable `+`-joined string, or `''` when
     * it names the API only in prose.
     */
    private function rolesIn(string $source): string
    {
        // Leading-backslash calls (`\Fiber::suspend()`) and imported ones read
        // the same after this, so the table does not have to carry both spellings.
        $code = str_replace(chr(92) . 'Fiber', 'Fiber', $this->withoutComments($source));

        $roles = [];

        if (str_contains($code, 'new Fiber(')) {
            $roles[] = 'creates';
        }

        if (str_contains($code, 'Fiber::suspend(')) {
            $roles[] = 'suspends';
        }

        if (str_contains($code, 'Fiber::getCurrent(')) {
            $roles[] = 'reads-current';
        }

        return implode('+', $roles);
    }

    private function withoutComments(string $source): string
    {
        $out = '';

        /** @var array<int, array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($source);

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $out .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                // A newline rather than nothing, so a `//` comment cannot fuse
                // the line above it to the line below.
                $out .= "\n";

                continue;
            }

            $out .= $token[1];
        }

        return $out;
    }
}
