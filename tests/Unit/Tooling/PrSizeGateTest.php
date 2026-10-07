<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesShellGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantedGitRepository;

use function sprintf;
use function str_repeat;

/**
 * Guards ADR-0031's 1500-line cap on a pull request.
 *
 * The cap exists because review effectiveness collapses well before that figure,
 * and "peer-reviewed" is a documented change-control claim for the deployments
 * this framework targets. A diff that nobody could have read cannot honestly
 * carry that claim, so tools/ci/check-pr-size.sh refuses it unless two people
 * say otherwise.
 *
 * Nothing had ever handed it an oversize diff and watched it refuse. These cases
 * do, and they assert the measurement as well as the verdict: a gate that refuses
 * without printing what it measured is one nobody can check, and a gate that
 * measured 0 lines because the diff range was empty would refuse nothing at all.
 *
 * NOT exercised here, and it needs a runner rather than a fixture: the bypass —
 * the `oversize-pr-acknowledged` label plus a `/oversize-pr-approved` comment
 * from a non-author CODEOWNER — is read from the GitHub API through `gh` for a
 * pull request that must exist. The refusal path is reachable without any of
 * that, which is why the merge-blocking half is testable and the bypass half is
 * recorded as untested rather than pretended.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/check-pr-size.sh', plants: 'a diff far past the 1500-line cap carrying neither the acknowledgement label nor a CODEOWNER approval')]
final class PrSizeGateTest extends TestCase
{
    use InvokesShellGate;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/check-pr-size.sh';

    private ?PlantedGitRepository $repository = null;

    protected function tearDown(): void
    {
        $this->repository?->remove();
        $this->repository = null;
    }

    /**
     * The planted defect: 2000 substantive lines, which is above the cap.
     *
     * No PR metadata is supplied, which is deliberate. An oversize diff with no
     * way to check the exemption is exactly where a gate is tempted to shrug, and
     * a shrug here would let the largest changes through precisely because their
     * context was missing.
     */
    #[Test]
    public function itFailsOnADiffNobodyCouldHaveReviewed(): void
    {
        [$status, $stdout] = $this->judge([
            'src/Ledger/Generated.php' => "<?php\n" . str_repeat("// a line of change\n", 2000),
        ]);

        self::assertSame(
            1,
            $status,
            'check-pr-size.sh accepted a 2000-line diff with no oversize approval. What ships on that '
            . 'silence: a change past the point where review is effective, merged carrying the '
            . '"peer-reviewed" attestation ADR-0031 makes a change-control record — an attestation '
            . 'nobody could have earned.',
        );
        self::assertStringContainsString('2001 insertions', $stdout);
        self::assertStringContainsString('exceeds the 1500-line cap', $stdout);
    }

    /**
     * The control: an ordinary change passes, and says what it measured.
     */
    #[Test]
    public function itPassesADiffThatCanBeReviewed(): void
    {
        [$status, $stdout] = $this->judge([
            'src/Ledger/Small.php' => "<?php\n" . str_repeat("// a line of change\n", 40),
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('PASS: under the 1500-line cap', $stdout);
    }

    /**
     * The exclusion list is part of the measurement, so it is part of the test.
     *
     * A lockfile update is thousands of lines of nothing to review. The planted
     * repository has no `.size-limit-ignore`, so this also pins the fallback list
     * the script uses on a fresh clone — if that list ever loses composer.lock, a
     * routine dependency bump starts demanding two-party approval and the cap
     * stops being taken seriously.
     */
    #[Test]
    public function itDoesNotCountExcludedPathsTowardTheCap(): void
    {
        [$status, $stdout] = $this->judge([
            'composer.lock' => sprintf("{\n%s}\n", str_repeat("  \"packages\": [],\n", 3000)),
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('No diff against base', $stdout);
    }

    /**
     * Plant a diff against a published base and let the gate judge it.
     *
     * @param array<string, string> $files path => contents, written on top of the base
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function judge(array $files): array
    {
        $bash = $this->bashBinary();

        if ($bash === null) {
            self::markTestSkipped(
                'No POSIX shell found (set PULSAR_BASH to one). tools/ci/check-pr-size.sh is a bash '
                . 'gate and cannot be driven without an interpreter; it runs on ubuntu-latest in CI, '
                . 'where this test does execute.',
            );
        }

        $repository = PlantedGitRepository::create('size');
        $this->repository = $repository;

        $repository->write('src/Core/Kernel.php', "<?php\n\nfinal class Kernel {}\n");
        $repository->commit('base tree');
        $repository->publishAsOrigin('main');

        foreach ($files as $path => $contents) {
            $repository->write($path, $contents);
        }

        $repository->commit('the change under judgement');

        return $this->runShellGate($bash, self::SCRIPT, $repository->path, [
            'BASE_REF' => 'main',
            // Cleared rather than inherited: under GitHub Actions these carry the
            // pull request that is running the suite, and the gate would go asking
            // the API about it.
            'PR_NUMBER' => '',
            'PR_AUTHOR' => '',
            'REPO_FULL' => '',
        ]);
    }
}
