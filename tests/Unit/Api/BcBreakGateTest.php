<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function file_put_contents;
use function is_file;
use function json_encode;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Watches the BC gate refuse a break on the stable surface.
 *
 * `Pulsar\Api\BcBreakDetector` spent the whole release-candidate phase reading a snapshot
 * shape nothing produced, returning an empty list for every input while
 * `docs/deprecation-policy.md` told readers CI ran it on every pull request. Both halves of
 * that were repaired: the detector reads the real document, and
 * `tools/api/assert-no-bc-breaks.php` is now a leaf of `composer qa` and a step in
 * `.github/workflows/ci.yml`.
 *
 * Which puts the gate itself under ADR-0060. A check never observed to fail is
 * indistinguishable from no check, and this gate's whole history is of a check that could
 * not fail while everyone believed it could. So each test here plants one break, runs the
 * script for real, and asserts the exit code.
 *
 * The `--base-file` / `--head-file` options exist for this: they let the refusal be watched
 * against two planted documents in about a second, instead of against a git history and a
 * full reflection scan of `src/` and `extensions/`.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/api/assert-no-bc-breaks.php', plants: 'a removed #[Api] method, a removed #[Api] class, a changed constructor signature, a removed constant, a base snapshot that is not JSON, and a base snapshot with no api_classes map')]
final class BcBreakGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/api/assert-no-bc-breaks.php';

    /** @var list<string> */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->planted = [];
    }

    #[Test]
    public function aRemovedApiMethodIsRefused(): void
    {
        $base = $this->plantSnapshot($this->snapshotWith(['go', 'stay']));
        $head = $this->plantSnapshot($this->snapshotWith(['stay']));

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $base,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing about this'),
        );

        self::assertSame(1, $status, 'a removed #[Api] method did not fail the gate');
        self::assertStringContainsString('Pulsar\Demo\Thing::go was removed', $stderr);
    }

    #[Test]
    public function aRemovedApiClassIsRefused(): void
    {
        $base = $this->plantSnapshot($this->snapshotWith(['go']));
        $head = $this->plantSnapshot(['api_classes' => [], 'internal_classes' => []]);

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $base,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing about this'),
        );

        self::assertSame(1, $status, 'a removed #[Api] class did not fail the gate');
        self::assertStringContainsString('Class Pulsar\Demo\Thing was removed', $stderr);
    }

    #[Test]
    public function aChangedSignatureIsRefused(): void
    {
        $base = $this->plantSnapshot($this->snapshotWith(['go']));

        $head = $this->plantSnapshot($this->snapshotWith(['go'], params: ['int $n', 'string $why']));

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $base,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing about this'),
        );

        self::assertSame(1, $status, 'a changed #[Api] signature did not fail the gate');
        self::assertStringContainsString('Signature of Pulsar\Demo\Thing::go changed', $stderr);
    }

    #[Test]
    public function aRemovedApiConstantIsRefused(): void
    {
        $base = $this->plantSnapshot($this->snapshotWith(['go']));

        $head = $this->plantSnapshot($this->snapshotWith(['go'], constants: []));

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $base,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing about this'),
        );

        self::assertSame(1, $status, 'a removed #[Api] constant did not fail the gate');
        self::assertStringContainsString('Pulsar\Demo\Thing::LIMIT was removed', $stderr);
    }

    /**
     * The acknowledgement path, which is what keeps the gate from being deleted.
     *
     * Pre-GA this repository removes `#[Api]` symbols on purpose. If the only way past the
     * gate were to put the symbol back, the gate would be removed the first time someone
     * meant it. Naming the symbol in `CHANGELOG.md` under `## [Unreleased]` is the way
     * past, and that is the sentence the release notes need anyway.
     */
    #[Test]
    public function aBreakNamedInTheUnreleasedChangelogIsAccepted(): void
    {
        $base = $this->plantSnapshot($this->snapshotWith(['go', 'stay']));
        $head = $this->plantSnapshot($this->snapshotWith(['stay']));

        [$status, $stdout] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $base,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('- **Breaking:** `Thing::go()` is removed; it did nothing.'),
        );

        self::assertSame(0, $status, 'a break recorded in the changelog was refused anyway');
        self::assertStringContainsString('1 break(s) acknowledged', $stdout);
    }

    /**
     * A base that cannot be read must stop the run, not read as an empty document.
     *
     * An empty base makes every symbol look newly added and reports all clear — which is
     * exactly the failure the detector was repaired out of, moved one layer up into the
     * script that feeds it.
     */
    #[Test]
    public function anUnreadableBaseSnapshotStopsTheRun(): void
    {
        $head = $this->plantSnapshot($this->snapshotWith(['go']));

        $corrupt = $this->plantFile('bc-corrupt', 'not json at all');

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $corrupt,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing'),
        );

        self::assertSame(2, $status, 'an unparseable base snapshot did not stop the run');
        self::assertStringContainsString('is not valid JSON', $stderr);

        $notASnapshot = $this->plantFile('bc-shape', (string) json_encode(['something' => 'else']));

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--base-file=' . $notASnapshot,
            '--head-file=' . $head,
            '--changelog=' . $this->plantChangelog('nothing'),
        );

        self::assertSame(2, $status, 'a document with no api_classes map did not stop the run');
        self::assertStringContainsString('it is not an API snapshot', $stderr);
    }

    /**
     * One `#[Api]` type with the named methods, parameters and constants.
     *
     * Every variation the tests need is a parameter here rather than a reach into the
     * returned array. A test that edited $snapshot['api_classes'][...]['signatures'] in
     * place would be indexing into `mixed` and would need annotating back to the shape
     * this method already knows.
     *
     * @param list<string> $methods
     * @param list<string> $params
     * @param list<string> $constants
     * @return array<string, mixed>
     */
    private function snapshotWith(array $methods, array $params = ['int $n'], array $constants = ['LIMIT']): array
    {
        $signatures = [];

        foreach ($methods as $method) {
            $signatures[$method] = ['params' => $params, 'return' => 'void', 'static' => false];
        }

        return [
            'api_classes' => [
                'Pulsar\Demo\Thing' => [
                    'since' => '1.0.0',
                    'signatures' => $signatures,
                    'constants' => $constants,
                ],
            ],
            'internal_classes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function plantSnapshot(array $snapshot): string
    {
        return $this->plantFile('bc-snapshot', (string) json_encode($snapshot));
    }

    private function plantChangelog(string $unreleasedBody): string
    {
        return $this->plantFile(
            'bc-changelog',
            "# Changelog\n\n## [Unreleased]\n\n### Removed\n\n" . $unreleasedBody . "\n\n## [1.0.0-rc.11] - 2026-02-12\n",
        );
    }

    private function plantFile(string $prefix, string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);
        file_put_contents($path, $contents);
        $this->planted[] = $path;

        return $path;
    }
}
