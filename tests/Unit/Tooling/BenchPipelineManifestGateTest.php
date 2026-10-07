<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;
use function str_replace;
use function substr;

/**
 * Plants a manifest change nobody wrote down, and observes the refusal.
 *
 * THE GATE THIS REPLACES could not fail. ci.yml computed
 * `hash_file('sha256', 'tools/php/bench-pipeline.manifest.php')`, printed it, and
 * put it in the job summary. Nothing compared it to anything. docs/performance.md
 * called the same step a gate — "this manifest is content-hashed in CI;
 * unauthorized changes fail the build" — and the manifest's own header repeated
 * the sentence, so three places asserted an enforcement that one `echo` was
 * providing.
 *
 * What that silence was covering is specific. The manifest declares which
 * middleware each request-class benchmark runs through, which storage backends are
 * real and which are stubs, and which side effects must actually happen. Delete
 * `session-start` from `request.authenticated_session`, or move a Tier B backend
 * to the null stub, and every number improves without anything getting faster —
 * while the budgets in tools/php/performance-budgets.json still carry the figures
 * derived under the old contract. That is the change this now refuses to let
 * through unannounced.
 *
 * Five cases, and each is a distinct way the gate could go quiet:
 *
 *   - the manifest edited and the digest left behind: the defect itself;
 *   - the digest edited and the manifest left alone: the same disagreement from
 *     the other side, which a gate reading only the manifest would miss;
 *   - no digest file at all, which must refuse rather than report a match against
 *     nothing — that is precisely the state the old step was in;
 *   - a digest file that is not a digest, which must be told apart from a digest
 *     that does not match, because the two need different fixes;
 *   - a CRLF manifest, which must PASS. The normalisation is a claim in the
 *     script's header, and an unobserved claim about a gate is what this whole
 *     class exists to stop.
 *
 * The healthy case is asserted against the real repository rather than a planted
 * tree: it is the one assertion that would also fail if the committed digest were
 * ever left stale, and running it anywhere else would prove the script works on
 * fixtures while the shipped pair drifted.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/assert-bench-manifest-integrity.php', plants: 'a benchmark pipeline manifest edited without its recorded digest, a digest edited without its manifest, a missing digest file, and a digest file that holds no digest')]
final class BenchPipelineManifestGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'tools/ci/assert-bench-manifest-integrity.php';

    private const string MANIFEST = 'tools/php/bench-pipeline.manifest.php';

    private const string DIGEST = 'tools/php/bench-pipeline.manifest.sha256';

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    /**
     * The control, over the shipped pair. Without it every refusal below would
     * also be produced by a script that refuses whatever it is handed.
     */
    #[Test]
    public function itAcceptsTheCommittedManifestAndItsRecordedDigest(): void
    {
        [$status, $stdout, $stderr] = $this->check();

        self::assertSame(
            0,
            $status,
            "the gate refuses the manifest and digest this repository actually ships. Either the\n"
            . "manifest changed without `--update` being run, or the digest was hand-edited.\n"
            . $stdout . $stderr,
        );
        self::assertStringContainsString('bench manifest: OK', $stdout);
    }

    /**
     * The planted defect: a middleware removed from a request class, and the
     * digest left at the value the old contract produced.
     */
    #[Test]
    public function itRefusesAManifestEditedWithoutItsDigest(): void
    {
        $tree = $this->plantTree('bench-manifest-edited');
        $manifest = $this->realManifest();

        $this->plantFile($tree, self::MANIFEST, str_replace("'session-start',", '', $manifest));
        $this->plantFile($tree, self::DIGEST, $this->digestOf($manifest) . '  ' . self::MANIFEST . "\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            'the gate accepted a manifest with a middleware deleted from it while the recorded '
            . 'digest still described the manifest that had it. What ships on that silence: '
            . 'request.authenticated_session measured without starting a session, every '
            . 'session-touching budget met by not doing the work, and a job summary printing a '
            . 'hash that agrees with nothing.',
        );
        self::assertStringContainsString('recorded:', $stderr);
        self::assertStringContainsString('actual:', $stderr);
    }

    /**
     * The same disagreement, arrived at from the other side.
     *
     * A gate that only ever recomputed the manifest and compared it with itself
     * would pass this. It is here because "the two files agree" is the property,
     * not "the manifest hashes to something".
     */
    #[Test]
    public function itRefusesADigestEditedWithoutItsManifest(): void
    {
        $tree = $this->plantTree('bench-manifest-digest-edited');
        $manifest = $this->realManifest();

        $this->plantFile($tree, self::MANIFEST, $manifest);
        $this->plantFile(
            $tree,
            self::DIGEST,
            // One character of the real digest changed: the shape of a bad merge
            // resolution, not of a deliberate edit.
            'a' . substr($this->digestOf($manifest), 1) . '  ' . self::MANIFEST . "\n",
        );

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(1, $status, 'a digest that no longer describes its manifest was accepted');
        self::assertStringContainsString('recorded: a', $stderr);
    }

    /**
     * The exact state the old CI step was in: a digest computed, and nothing to
     * compare it against.
     */
    #[Test]
    public function itRefusesAMissingDigestRatherThanReportingAMatchAgainstNothing(): void
    {
        $tree = $this->plantTree('bench-manifest-no-digest');
        $this->plantFile($tree, self::MANIFEST, $this->realManifest());

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            2,
            $status,
            'a manifest with no recorded digest was reported as verified. That is the gate this '
            . 'one replaces, reproduced: a hash printed into a job summary, agreeing with nothing, '
            . 'under a step named "Verify pipeline manifest integrity".',
        );
        self::assertStringContainsString('nothing for the manifest to be checked against', $stderr);
    }

    /**
     * A digest file that holds no digest must be told apart from one that holds
     * the wrong digest: exit 2 and a different message, because the fix differs.
     */
    #[Test]
    public function itRefusesADigestFileThatHoldsNoDigest(): void
    {
        $tree = $this->plantTree('bench-manifest-unreadable-digest');
        $this->plantFile($tree, self::MANIFEST, $this->realManifest());
        $this->plantFile($tree, self::DIGEST, "# regenerate me\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(2, $status);
        self::assertStringContainsString('64-character hex digest', $stderr);
    }

    /**
     * A missing manifest is an unknown contract, not an empty one.
     */
    #[Test]
    public function itRefusesAMissingManifest(): void
    {
        $tree = $this->plantTree('bench-manifest-absent');
        $this->plantFile($tree, self::DIGEST, $this->digestOf($this->realManifest()) . '  ' . self::MANIFEST . "\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(2, $status);
        self::assertStringContainsString('does not exist', $stderr);
    }

    /**
     * The normalisation, observed rather than asserted in a comment.
     *
     * .gitattributes pins the working tree to LF, so this is the checkout nobody
     * should have — which is exactly why it is planted: a contributor who ends up
     * with CRLF anyway must get a passing gate, not a mismatch whose message
     * cannot explain itself.
     */
    #[Test]
    public function itAcceptsACrlfManifestAgainstAnLfDigest(): void
    {
        $tree = $this->plantTree('bench-manifest-crlf');
        $manifest = $this->realManifest();

        $this->plantFile($tree, self::MANIFEST, str_replace("\n", "\r\n", $manifest));
        $this->plantFile($tree, self::DIGEST, $this->digestOf($manifest) . '  ' . self::MANIFEST . "\n");

        [$status, $stdout, $stderr] = $this->check($tree);

        self::assertSame(
            0,
            $status,
            "a CRLF checkout of an unchanged manifest was reported as a manifest that had changed.\n"
            . "A gate that fails for a reason its own message cannot explain is a gate that gets\n"
            . 'bypassed, and this one guards a contract worth not bypassing.' . $stdout . $stderr,
        );
    }

    /**
     * The manifest as this repository ships it, with line endings normalised the
     * way the gate normalises them.
     *
     * Read rather than invented: a fixture manifest of the test's own devising
     * would drift from the shape the script actually meets, and the CRLF case
     * below needs a document that genuinely contains LF newlines.
     */
    private function realManifest(): string
    {
        $contents = file_get_contents($this->repositoryRoot() . '/' . self::MANIFEST);

        self::assertIsString($contents, 'could not read the committed pipeline manifest');

        return str_replace("\r\n", "\n", $contents);
    }

    private function digestOf(string $manifest): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $manifest));
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function check(?string $root = null): array
    {
        $command = [$this->repositoryRoot() . '/' . self::SCRIPT];

        if ($root !== null) {
            $command[] = '--root=' . $root;
        }

        return $this->runGate($command);
    }
}
