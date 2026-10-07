<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesShellGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantedGitRepository;

/**
 * Guards ADR-0001's enforcement: core architecture cannot change unrecorded.
 *
 * scripts/check-adr.sh is the only thing standing between a change to src/Core,
 * src/Container, src/Routing, src/Http, src/Extensibility, src/Api or src/Config
 * and a merge with no architectural decision record behind it. For a framework
 * whose deployments are regulated, the ADR trail is the change-control evidence,
 * so a gate that never refuses is a control that was never implemented.
 *
 * Nothing had ever planted a core change and watched it refuse. These cases do,
 * against a real repository with a real base ref, because what CI branches on is
 * the exit code of `bash scripts/check-adr.sh` — not a reimplementation of it.
 *
 * The second case is the one a reviewer cannot see by reading: the script's own
 * comment claims that touching docs/adr/0000-template.md does not satisfy the
 * gate. That claim is asserted here rather than trusted, because a looser pattern
 * would let a typo fix on the template license an unrecorded rewrite of the
 * kernel.
 *
 * The two-party bypass path (the `adr-exempt` label plus a `/adr-exempt-approved`
 * comment from a non-author CODEOWNER) is not exercised: it consults the GitHub
 * API through `gh` for a pull request that must exist. The refusal path — the one
 * that blocks merges — reaches its FAIL before any of that, which is why it is
 * testable here at all.
 */
#[CoversNothing]
#[GuardsGate(gate: 'scripts/check-adr.sh', plants: 'a change to src/Core carrying no new or updated ADR, and the ADR template submitted in place of an ADR')]
final class AdrGovernanceGateTest extends TestCase
{
    use InvokesShellGate;

    private const string SCRIPT = __DIR__ . '/../../../scripts/check-adr.sh';

    private ?PlantedGitRepository $repository = null;

    protected function tearDown(): void
    {
        $this->repository?->remove();
        $this->repository = null;
    }

    /**
     * The planted defect: src/Core changes, no ADR anywhere in the diff.
     */
    #[Test]
    public function itFailsWhenCoreArchitectureChangesWithoutAnAdr(): void
    {
        [$status, $stdout] = $this->judge([
            'src/Core/Kernel.php' => "<?php\n\n// a new boot path, decided by nobody\n",
        ]);

        self::assertSame(
            1,
            $status,
            'check-adr.sh accepted a change to src/Core with no ADR. What ships on that silence: '
            . 'an architectural change to the kernel merged with no decision record, in a repository '
            . 'whose ADR trail is the documented change-control evidence for regulated deployments — '
            . 'ADR-0001 enforced by nothing.',
        );
        self::assertStringContainsString('Core architecture paths changed without an ADR', $stdout);
        self::assertStringContainsString('src/Core/Kernel.php', $stdout);
    }

    /**
     * The subtler plant: an ADR-shaped file that is not an ADR.
     *
     * A change to the template is a change to the form, not a decision. The
     * script's comment says a looser match would accept it; this asserts it does
     * not, because a gate that can be satisfied by editing a template is a gate
     * anyone can satisfy without deciding anything.
     */
    #[Test]
    public function itRefusesTheAdrTemplateInPlaceOfAnAdr(): void
    {
        [$status, $stdout] = $this->judge([
            'src/Routing/Router.php' => "<?php\n\n// new dispatch strategy\n",
            'docs/adr/0000-template.md' => "# ADR-0000: Template\n\nFixed a typo.\n",
        ]);

        self::assertSame(
            1,
            $status,
            'check-adr.sh accepted docs/adr/0000-template.md as the ADR for a routing rewrite. '
            . 'What ships on that silence: any core change waved through by a whitespace fix on '
            . 'the template, which is the ADR gate satisfying itself.',
        );
        self::assertStringContainsString('Core architecture paths changed without an ADR', $stdout);
    }

    /**
     * The control: a real ADR in the same diff passes.
     *
     * Without it the refusals above are equally explained by a script that refuses
     * everything, which blocks merges while measuring nothing.
     */
    #[Test]
    public function itPassesWhenTheCoreChangeCarriesAnAdr(): void
    {
        [$status, $stdout] = $this->judge([
            'src/Core/Kernel.php' => "<?php\n\n// a new boot path\n",
            'docs/adr/0099-new-boot-path.md' => "# ADR-0099: New boot path\n\nStatus: Accepted\n",
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('PASS', $stdout);
    }

    /**
     * The other control: the gate is scoped, and does not demand an ADR of everything.
     */
    #[Test]
    public function itPassesWhenNoCoreArchitecturePathChanged(): void
    {
        [$status, $stdout] = $this->judge([
            'docs/testing.md' => "# Testing\n\nA documentation edit.\n",
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('PASS', $stdout);
    }

    /**
     * Plant a change on a branch and let the gate judge it.
     *
     * @param array<string, string> $files path => contents, added on the branch
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function judge(array $files): array
    {
        $bash = $this->bashBinary();

        if ($bash === null) {
            self::markTestSkipped(
                'No POSIX shell found (set PULSAR_BASH to one). scripts/check-adr.sh is a bash '
                . 'gate and cannot be driven without an interpreter; it runs on ubuntu-latest in CI, '
                . 'where this test does execute.',
            );
        }

        $repository = PlantedGitRepository::create('adr');
        $this->repository = $repository;

        $baseSha = $repository->headSha();

        foreach ($files as $path => $contents) {
            $repository->write($path, $contents);
        }

        $repository->commit('the change under judgement');

        return $this->runShellGate($bash, self::SCRIPT, $repository->path, [
            'BASE_SHA' => $baseSha,
            // Emptied rather than inherited: under GitHub Actions this variable
            // points at a real event payload, and the gate would read labels from
            // whatever pull request happened to be running the suite.
            'GITHUB_EVENT_PATH' => '',
            'GITHUB_BASE_REF' => '',
        ]);
    }
}
