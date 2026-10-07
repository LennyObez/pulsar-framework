<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rand;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Watches the ADR index gate refuse a drifted table.
 *
 * The index in docs/adr/README.md is generated because a hand-written list of
 * seventy-odd decisions is a second copy of a fact, and this repository has
 * already paid for one of those: docs/compliance.md carried about 550 cells typed
 * by a human and verified by nobody. Generating it is only half the repair —
 * a generator nobody runs leaves the same stale table behind, so the gate has a
 * --check mode that fails on drift.
 *
 * Which means the gate itself needs the treatment ADR-0060 prescribes. A check
 * never observed to fail is indistinguishable from no check, so these tests plant
 * a drifted table, a missing marker and a statusless record, and assert the script
 * refuses each. Without them, --check could silently exit 0 forever and nobody
 * would learn until the index was quoted at someone.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/build-adr-index.php', plants: 'an index table that disagrees with the series, a record with no status, a record still carrying the unfilled template status line, two records numbered the same, a README with no generated-index markers, a cross-reference resolving to a file that does not exist, and a record citing a superseded decision without naming the record that replaced it')]
final class AdrIndexGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/build-adr-index.php';

    private const string BEGIN = '<!-- BEGIN GENERATED INDEX -->';

    private const string END = '<!-- END GENERATED INDEX -->';

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
    public function theCommittedIndexMatchesTheRecordsItDescribes(): void
    {
        [$status, $stdout, $stderr] = $this->runScript(self::SCRIPT, '--check');

        self::assertSame(0, $status, $stdout . $stderr);
        self::assertStringContainsString('matches the', $stdout);
    }

    #[Test]
    public function aDriftedTableIsRefused(): void
    {
        // The failure this exists to catch: a record is added, renamed or has its
        // status changed, and the index keeps describing the series as it was.
        $root = $this->plantSeries([
            '0001-first.md' => "# ADR-0001: First\n\n## Status\n\nAccepted\n",
            '0002-second.md' => "# ADR-0002: Second\n\n## Status\n\nAccepted\n",
        ]);

        $this->writeIndex($root, '**1 records: 1 in force, 0 superseded or deprecated.**');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--check', '--root=' . $root);

        self::assertSame(1, $status, 'a table that disagrees with the series must not pass');
        self::assertStringContainsString('out of date', $stderr);
    }

    #[Test]
    public function aRecordWithNoStatusIsRefusedRatherThanIndexedAsUnknown(): void
    {
        // Listing a statusless record as "(none)" would let the series rot while
        // the index looked complete. The generator refuses instead, because an
        // index describes a series and cannot repair one.
        $root = $this->plantSeries([
            '0001-decided.md' => "# ADR-0001: Decided\n\n## Status\n\nAccepted\n",
            '0002-undecided.md' => "# ADR-0002: Undecided\n\nNo status section at all.\n",
        ]);

        $this->writeIndex($root, 'anything');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status);
        self::assertStringContainsString('declares no status', $stderr);
    }

    #[Test]
    public function anUnfilledTemplateStatusIsRefused(): void
    {
        $root = $this->plantSeries([
            '0001-copied.md' => "# ADR-0001: Copied\n\n## Status\n\nProposed | Accepted | Deprecated | Superseded by ADR-NNNN\n",
        ]);

        $this->writeIndex($root, 'anything');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status, 'a record that never replaced the template line is not decided');
        self::assertStringContainsString('unfilled template', $stderr);
    }

    #[Test]
    public function aDuplicateNumberIsRefused(): void
    {
        // Two records once collided on 0057. A series that tolerates a duplicate
        // number cannot be cited unambiguously, which is the whole point of it.
        $root = $this->plantSeries([
            '0001-one.md' => "# ADR-0001: One\n\n## Status\n\nAccepted\n",
            '0001-also-one.md' => "# ADR-0001: Also one\n\n## Status\n\nAccepted\n",
        ]);

        $this->writeIndex($root, 'anything');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status);
        self::assertStringContainsString('used twice', $stderr);
    }

    #[Test]
    public function aDanglingCrossReferenceIsRefused(): void
    {
        // A record hands a reader forward with a relative link, and a broken one
        // is invisible until somebody clicks. Ten records in this series linked
        // to `0001-architecture-decision-records.md`, a filename that has never
        // existed here, and they were carried for the whole RC phase because
        // nothing resolved them.
        $root = $this->plantSeries([
            '0001-first.md' => '# ADR-0001: First

## Status

Accepted
',
            '0002-second.md' => '# ADR-0002: Second

## Status

Accepted

'
                . 'Superseded by [ADR-0003](0003-a-record-that-was-never-written.md).
',
        ]);

        $this->writeIndex($root, 'anything');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status, 'a cross-reference that resolves to nothing must not pass');
        self::assertStringContainsString('which does not exist', $stderr);
    }

    #[Test]
    public function aPointerAtASupersededRecordWithoutItsSuccessorIsRefused(): void
    {
        // Resolving is not the same as being current, and the link check above
        // cannot tell the two apart. ADR-0057's Links section sent a reader to
        // ADR-0005 for the Fiber rule ADR-0071 had already replaced; the link was
        // perfect and the answer at the end of it was retracted. Two more records
        // were doing the same thing at ADR-0004, and all three had been doing it
        // since the day the supersessions landed.
        $root = $this->plantSeries([
            '0001-old.md' => '# ADR-0001: Old

## Status

Superseded by [ADR-0002](0002-new.md).
',
            '0002-new.md' => '# ADR-0002: New

## Status

Accepted. Supersedes [ADR-0001](0001-old.md).
',
            '0003-citing.md' => '# ADR-0003: Citing

## Status

Accepted

See [ADR-0001](0001-old.md) for the rule.
',
        ]);

        $this->writeIndex($root, 'anything');

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status, 'a live record may not hand a reader a retracted decision on its own');
        self::assertStringContainsString('which superseded it', $stderr);
    }

    #[Test]
    public function aPointerThatNamesTheSuccessorAsWellIsAccepted(): void
    {
        // The complement, and the reason the check is about the pair rather than
        // about citing a superseded record at all: citing one is legitimate and
        // often the point -- ADR-0069, ADR-0070 and ADR-0072 each cite the record
        // they replaced. What is not legitimate is citing it alone.
        $root = $this->plantSeries([
            '0001-old.md' => '# ADR-0001: Old

## Status

Superseded by [ADR-0002](0002-new.md).
',
            '0002-new.md' => '# ADR-0002: New

## Status

Accepted. Supersedes [ADR-0001](0001-old.md).
',
            '0003-citing.md' => '# ADR-0003: Citing

## Status

Accepted

'
                . 'Governed by [ADR-0002](0002-new.md), which superseded '
                . '[ADR-0001](0001-old.md).
',
        ]);

        $this->writeIndex($root, 'anything');

        [$status, $stdout, $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(0, $status, $stdout . $stderr);
        self::assertStringNotContainsString('which superseded it', $stderr);
    }

    #[Test]
    public function anExternalUrlAndABareAnchorAreNotTreatedAsFiles(): void
    {
        // The complement of the test above: the check must not start failing on
        // links it was never meant to resolve, or the first author to cite an RFC
        // learns to distrust it.
        $root = $this->plantSeries([
            '0001-first.md' => '# ADR-0001: First

## Status

Accepted

'
                . 'See [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110) and '
                . '[the decision](#status) and [the next one](0002-second.md#context).
',
            '0002-second.md' => '# ADR-0002: Second

## Status

Accepted
',
        ]);

        $this->writeIndex($root, 'anything');

        // Generate rather than --check: the planted index is deliberately wrong,
        // so a passing run here means the link check raised nothing.
        [$status, $stdout, $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(0, $status, $stdout . $stderr);
        self::assertStringNotContainsString('does not exist', $stderr);
    }

    #[Test]
    public function aMissingMarkerIsRefusedRatherThanAppended(): void
    {
        // Writing the table wherever it happened to fit would let a careless edit
        // silently relocate or duplicate the index.
        $root = $this->plantSeries([
            '0001-first.md' => "# ADR-0001: First\n\n## Status\n\nAccepted\n",
        ]);

        $path = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'adr' . DIRECTORY_SEPARATOR . 'README.md';
        file_put_contents($path, "# Architecture Decision Records\n\nNo markers here.\n");
        $this->planted[] = $path;

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $root);

        self::assertSame(1, $status);
        self::assertStringContainsString('markers', $stderr);
    }

    /**
     * @param array<string, string> $records
     */
    private function plantSeries(array $records): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_adr_' . rand(100000, 999999);
        $dir = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'adr';

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        foreach ($records as $name => $body) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, $body);
            $this->planted[] = $path;
        }

        return $root;
    }

    private function writeIndex(string $root, string $table): void
    {
        $path = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'adr' . DIRECTORY_SEPARATOR . 'README.md';

        file_put_contents(
            $path,
            "# Architecture Decision Records\n\n" . self::BEGIN . "\n\n" . $table . "\n\n" . self::END . "\n",
        );

        $this->planted[] = $path;
    }
}
