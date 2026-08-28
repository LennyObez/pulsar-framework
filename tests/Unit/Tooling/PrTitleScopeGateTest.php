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
 * Guards the gate that stops a pull request from understating itself.
 *
 * tools/ci/check-pr-title-scope.sh refuses a `chore:` or `docs:` title on a diff
 * that adds a new module under src/ or a new extension. The reason is not tidiness:
 * reviewers triage by title, the prefix drives release-note generation, and a new
 * core module arriving under a `chore:` heading gets both less scrutiny than it
 * needs and no mention downstream. In a regulated deployment that scrutiny gap is
 * a change-control gap.
 *
 * Nothing had ever handed it an understated title and watched it refuse, so the
 * cases below do — through a real repository, because the gate's whole judgement
 * is `git diff --diff-filter=A` against a base ref and `git ls-tree` of that base.
 * A stubbed git would test the stub.
 *
 * Both controls matter as much as the refusals. A script that refused every title
 * would pass the negative cases while blocking every merge in the repository, and
 * a script that never refused would pass a control-only test.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/check-pr-title-scope.sh', plants: 'a chore: title on a diff adding a new src/ module, and a docs: title on a diff adding a new extension')]
final class PrTitleScopeGateTest extends TestCase
{
    use InvokesShellGate;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/check-pr-title-scope.sh';

    private ?PlantedGitRepository $repository = null;

    protected function tearDown(): void
    {
        $this->repository?->remove();
        $this->repository = null;
    }

    /**
     * The planted defect: a whole new core module, announced as housekeeping.
     */
    #[Test]
    public function itFailsWhenAChoreTitleAddsANewCoreModule(): void
    {
        [$status, $stdout] = $this->judge('chore: tidy up a few helpers', [
            'src/Ledger/PostingEngine.php' => "<?php\n\nfinal class PostingEngine {}\n",
            'src/Ledger/Account.php' => "<?php\n\nfinal class Account {}\n",
        ]);

        self::assertSame(
            1,
            $status,
            'check-pr-title-scope.sh accepted "chore:" on a diff adding src/Ledger. What ships on '
            . 'that silence: a new core module reviewed at housekeeping depth and absent from the '
            . 'release notes the prefix generates — the reviewer and the changelog both told it was '
            . 'nothing.',
        );
        self::assertStringContainsString('PR title-scope mismatch', $stdout);
        self::assertStringContainsString('src/Ledger', $stdout);
    }

    /**
     * The same deception in the other direction the script names: a new extension.
     */
    #[Test]
    public function itFailsWhenADocsTitleAddsANewExtension(): void
    {
        [$status, $stdout] = $this->judge('docs(ext): clarify the extension guide', [
            'extensions/payroll/pulsar.json' => "{\n  \"name\": \"payroll\"\n}\n",
            'extensions/payroll/src/PayrollExtension.php' => "<?php\n\nfinal class PayrollExtension {}\n",
        ]);

        self::assertSame(
            1,
            $status,
            'check-pr-title-scope.sh accepted "docs:" on a diff adding extensions/payroll. What ships '
            . 'on that silence: an entire extension — its manifest, its trust tier, its code — merged '
            . 'under a title that promised a documentation edit.',
        );
        self::assertStringContainsString('New extensions added', $stdout);
        self::assertStringContainsString('extensions/payroll', $stdout);
    }

    /**
     * The control: the same diff, honestly titled, passes.
     */
    #[Test]
    public function itPassesWhenTheTitleDeclaresTheScope(): void
    {
        [$status, $stdout] = $this->judge('feat(core): add the ledger module', [
            'src/Ledger/PostingEngine.php' => "<?php\n\nfinal class PostingEngine {}\n",
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('scope-strict rule does not apply', $stdout);
    }

    /**
     * The other control: `chore:` on work that really is one.
     *
     * Editing a file inside a module that already exists at the base adds no
     * module, so the rule must stay silent. Without this the gate could be
     * satisfying its negative cases by refusing every chore-titled PR.
     */
    #[Test]
    public function itPassesWhenAChoreTitleTouchesOnlyExistingModules(): void
    {
        [$status, $stdout] = $this->judge('chore(core): tighten a docblock', [
            'src/Core/Kernel.php' => "<?php\n\n// an edit to a file that already existed\n",
        ]);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('title-scope rule passes', $stdout);
    }

    /**
     * Plant a diff against a base that already has src/Core and one extension.
     *
     * @param array<string, string> $files path => contents, written on top of the base
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function judge(string $title, array $files): array
    {
        $bash = $this->bashBinary();

        if ($bash === null) {
            self::markTestSkipped(
                'No POSIX shell found (set PULSAR_BASH to one). tools/ci/check-pr-title-scope.sh is a '
                . 'bash gate and cannot be driven without an interpreter; it runs on ubuntu-latest in '
                . 'CI, where this test does execute.',
            );
        }

        $repository = PlantedGitRepository::create('title');
        $this->repository = $repository;

        // The base has to contain modules for the gate to tell a NEW one from an
        // edit — the whole judgement is "which top-level directories are not in
        // `git ls-tree` of the base".
        $repository->write('src/Core/Kernel.php', "<?php\n\nfinal class Kernel {}\n");
        $repository->write('extensions/example/pulsar.json', "{\n  \"name\": \"example\"\n}\n");
        $repository->commit('base tree');
        $repository->publishAsOrigin('main');

        foreach ($files as $path => $contents) {
            $repository->write($path, $contents);
        }

        $repository->commit('the change under judgement');

        return $this->runShellGate($bash, self::SCRIPT, $repository->path, [
            'PR_TITLE' => $title,
            'BASE_REF' => 'main',
        ]);
    }
}
