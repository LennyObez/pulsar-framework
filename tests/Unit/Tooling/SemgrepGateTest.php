<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function is_file;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Guards the gate that guards the Semgrep ruleset.
 *
 * The ruleset spent its whole life unable to fail anything: no workflow ran it,
 * and `composer security:lint` passed neither `--error` nor `--severity`. Replacing
 * that with a script means the script is now the thing standing between a rule and
 * a merge, and a gate is worth exactly its own correctness.
 *
 * The cases below are chosen for the ways this particular gate could go quietly
 * wrong rather than for coverage of its branches:
 *
 *   - It could pass a finding it has never seen, which is the old behaviour with
 *     extra steps.
 *   - It could pass a SECOND occurrence of a match it has seen once, because the
 *     baseline records identities and not counts.
 *   - It could treat a file Semgrep abandoned as a clean file, which is how a gate
 *     grows a hole nobody notices.
 *   - It could treat Semgrep's recoverable parse errors — 744 of them on this tree
 *     — as holes, which would make the blind-spot check unusable and therefore
 *     turned off.
 *   - It could report success when Semgrep was absent or its output unreadable,
 *     making "the tool did not run" indistinguishable from "the tool found
 *     nothing". That distinction is the entire subject of this change.
 *
 * Semgrep itself is not installed to run these. `--report` feeds the script a
 * report and `--baseline` a baseline, because what is worth testing is what the
 * script CONCLUDES, not that a subprocess starts.
 */
#[GuardsGate(gate: 'tools/security/assert-semgrep-clean.php', plants: 'a finding absent from the baseline, a second occurrence of a baselined match, a file Semgrep could not parse, and a blind spot still carrying the placeholder reason')]
final class SemgrepGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/security/assert-semgrep-clean.php';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
    }

    #[Test]
    public function itPassesWhenEveryFindingIsRecordedInTheBaseline(): void
    {
        $report = $this->report([$this->finding('src/A.php', 12, "hash('sha256', \$x)")]);
        $baseline = $this->baseline([$this->baselineEntry('src/A.php', "hash('sha256', \$x)")]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('all recorded in the baseline', $stdout);
    }

    #[Test]
    public function itFailsOnAFindingTheBaselineDoesNotContain(): void
    {
        $report = $this->report([$this->finding('src/New.php', 3, "hash('sha256', \$secret)")]);
        $baseline = $this->baseline([]);

        [$status, , $stderr] = $this->invoke($report, $baseline);

        self::assertSame(1, $status);
        self::assertStringContainsString('not present in the baseline', $stderr);
        self::assertStringContainsString('src/New.php:3', $stderr);
    }

    /**
     * The case a fingerprint-only baseline gets wrong.
     *
     * Two identical calls in one file share every component of the identity, so
     * without a recorded count the second one is silently adopted by the first
     * one's entry — a new violation entering the tree under cover of an old one.
     */
    #[Test]
    public function itFailsOnASecondOccurrenceOfAnAlreadyBaselinedMatch(): void
    {
        $snippet = "hash('sha256', \$x)";
        $report = $this->report([
            $this->finding('src/A.php', 12, $snippet),
            $this->finding('src/A.php', 40, $snippet),
        ]);
        $baseline = $this->baseline([$this->baselineEntry('src/A.php', $snippet, count: 1)]);

        [$status, , $stderr] = $this->invoke($report, $baseline);

        self::assertSame(1, $status);
        self::assertStringContainsString('baseline allows 1 occurrence(s)', $stderr);
        self::assertStringContainsString('found 2', $stderr);
    }

    #[Test]
    public function itAcceptsBothOccurrencesWhenTheBaselineRecordsTwo(): void
    {
        $snippet = "hash('sha256', \$x)";
        $report = $this->report([
            $this->finding('src/A.php', 12, $snippet),
            $this->finding('src/A.php', 40, $snippet),
        ]);
        $baseline = $this->baseline([$this->baselineEntry('src/A.php', $snippet, count: 2)]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
    }

    /**
     * A finding whose line moved must not read as a new finding.
     *
     * If it did, every edit above a baselined call would turn the gate red, people
     * would regenerate the baseline to clear it, and regenerating on reflex is what
     * turns a ratchet back into a rubber stamp.
     */
    #[Test]
    public function itIgnoresTheLineNumberWhenMatchingTheBaseline(): void
    {
        $snippet = "hash('sha256', \$x)";
        $report = $this->report([$this->finding('src/A.php', 900, $snippet)]);
        $baseline = $this->baseline([$this->baselineEntry('src/A.php', $snippet)]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
    }

    #[Test]
    public function itFailsOnAFileSemgrepCouldNotParseAtAll(): void
    {
        $report = $this->report([], [['type' => 'Syntax error', 'path' => 'src/Broken.php']]);
        $baseline = $this->baseline([]);

        [$status, , $stderr] = $this->invoke($report, $baseline);

        self::assertSame(1, $status);
        self::assertStringContainsString('could not parse at all', $stderr);
        self::assertStringContainsString('src/Broken.php', $stderr);
    }

    /**
     * 744 files on this tree produce PartialParsing, because Semgrep's PHP frontend
     * rejects `readonly class`. The recovery is real — the class body is still
     * scanned — so treating these as blind spots would bury the one file that IS a
     * blind spot under 744 that are not.
     */
    #[Test]
    public function itDoesNotTreatARecoverableParseErrorAsABlindSpot(): void
    {
        $report = $this->report([], [['type' => ['PartialParsing', []], 'path' => 'src/Readonly.php']]);
        $baseline = $this->baseline([]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('0 unparsed file(s)', $stdout);
    }

    #[Test]
    public function itAcceptsABlindSpotThatCarriesAReason(): void
    {
        $report = $this->report([], [['type' => 'Syntax error', 'path' => 'src/Broken.php']]);
        $baseline = $this->baseline([], [['path' => 'src/Broken.php', 'reason' => 'measured: the frontend gives up at the attribute on line 26']]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
    }

    /**
     * A hole somebody recorded but never justified is a hole nobody decided to
     * accept. The generator writes the placeholder precisely so this can fail.
     */
    #[Test]
    public function itFailsWhenARecordedBlindSpotStillCarriesThePlaceholderReason(): void
    {
        $report = $this->report([], [['type' => 'Syntax error', 'path' => 'src/Broken.php']]);
        $baseline = $this->baseline([], [['path' => 'src/Broken.php', 'reason' => 'UNJUSTIFIED: state why this file cannot be parsed and what that hides.']]);

        [$status, , $stderr] = $this->invoke($report, $baseline);

        self::assertSame(1, $status);
        self::assertStringContainsString('placeholder reason', $stderr);
    }

    #[Test]
    public function itReportsShrinkingDebtWithoutFailing(): void
    {
        $report = $this->report([]);
        $baseline = $this->baseline([$this->baselineEntry('src/Fixed.php', "hash('sha256', \$x)")]);

        [$status, $stdout] = $this->invoke($report, $baseline);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('no longer match', $stdout);
    }

    /**
     * The threshold is the lowest severity any rule uses. An INFO rule would be
     * below it and must be filtered; an ERROR rule is above it and must not be,
     * which is why the threshold is applied to findings here rather than handed to
     * Semgrep as `--severity` (that flag selects which rules RUN).
     */
    #[Test]
    public function itFiltersBelowTheThresholdAndKeepsAbove(): void
    {
        $report = $this->report([
            $this->finding('src/Info.php', 1, 'noise()', severity: 'INFO'),
            $this->finding('src/Error.php', 2, 'danger()', severity: 'ERROR'),
        ]);
        $baseline = $this->baseline([]);

        [$status, , $stderr] = $this->invoke($report, $baseline);

        self::assertSame(1, $status);
        self::assertStringContainsString('src/Error.php', $stderr);
        self::assertStringNotContainsString('src/Info.php', $stderr);
    }

    #[Test]
    public function itRefusesToPassWhenTheBaselineIsMissing(): void
    {
        $report = $this->report([]);

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--report=' . $report,
            '--baseline=' . sys_get_temp_dir() . '/pulsar-semgrep-absent-baseline.json',
        );

        self::assertSame(2, $status);
        self::assertStringContainsString('missing or unreadable', $stderr);
    }

    #[Test]
    public function itRefusesToPassWhenTheReportCannotBeRead(): void
    {
        $baseline = $this->baseline([]);

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--report=' . sys_get_temp_dir() . '/pulsar-semgrep-absent-report.json',
            '--baseline=' . $baseline,
        );

        self::assertSame(2, $status);
        self::assertStringContainsString('could not obtain a report', $stderr);
    }

    #[Test]
    public function itRejectsAnUnknownArgumentRatherThanIgnoringIt(): void
    {
        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--sevrity=ERROR');

        self::assertSame(2, $status);
        self::assertStringContainsString('Unrecognised argument', $stderr);
    }

    #[Test]
    public function itRejectsASeverityItDoesNotKnow(): void
    {
        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--severity=CRITICAL');

        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown severity', $stderr);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(string $report, string $baseline): array
    {
        return $this->runScript(self::SCRIPT, '--report=' . $report, '--baseline=' . $baseline);
    }

    /**
     * @return array{check_id: string, path: string, start: array{line: int}, extra: array{lines: string, severity: string}}
     */
    private function finding(string $path, int $line, string $snippet, string $severity = 'WARNING'): array
    {
        return [
            'check_id' => 'tools.security.pulsar.security.forbidden-sha256',
            'path' => $path,
            'start' => ['line' => $line],
            'extra' => ['lines' => $snippet, 'severity' => $severity],
        ];
    }

    /**
     * @return array{fingerprint: string, rule: string, path: string, count: int, snippet: string}
     */
    private function baselineEntry(string $path, string $snippet, int $count = 1): array
    {
        $rule = 'tools.security.pulsar.security.forbidden-sha256';

        return [
            // The identity the script computes, reproduced here rather than imported,
            // so a change to the fingerprint recipe surfaces as a failing test instead
            // of as a baseline that quietly matches nothing.
            'fingerprint' => substr(hash('sha256', $rule . "\0" . $path . "\0" . $snippet), 0, 32),
            'rule' => $rule,
            'path' => $path,
            'count' => $count,
            'snippet' => $snippet,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $errors
     */
    private function report(array $results, array $errors = []): string
    {
        return $this->write(['results' => $results, 'errors' => $errors]);
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $unparsed
     */
    private function baseline(array $findings, array $unparsed = []): string
    {
        return $this->write([
            'semgrep_version' => '1.155.0',
            'severity_threshold' => 'WARNING',
            'findings' => $findings,
            'unparsed' => $unparsed,
        ]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function write(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar-semgrep-test-');
        self::assertIsString($path);

        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        $this->written[] = $path;

        return $path;
    }
}
