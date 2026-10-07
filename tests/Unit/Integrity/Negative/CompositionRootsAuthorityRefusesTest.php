<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\SecondDefinitionScanner;

use function implode;

/**
 * The composition-root authority rule, watched refusing.
 *
 * The rule decides which code may cross module boundaries, and it once had three
 * definitions that had already drifted: `Pulsar\Core\Boot\` was a root for the static
 * boundary checker and not for the runtime guard, so a class there passed the gate and
 * would have been refused when it ran; the wiring checker was narrower still and
 * reported boot classes as unwired.
 *
 * Deleting the copies fixed that day. Keeping them deleted is what the rule claims, and
 * the claim had never been checked against a repository that contains a copy — because
 * the scan could only read this one, where they are already gone. So each shape a copy
 * can take is planted here and the rule is watched naming it.
 */
#[CoversClass(SecondDefinitionScanner::class)]
#[GuardsGate(
    gate: 'CompositionRootsAuthorityTest::noSecondDefinitionExists',
    plants: 'a src/ class and a tools/ script each redeclaring the composition-root list',
)]
final class CompositionRootsAuthorityRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** The authority, present in every fixture: the one file allowed to declare this. */
    private const string AUTHORITY = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Pulsar\Api;

        final class CompositionRoots
        {
            public const array COMPOSITION_ROOTS = ['Pulsar\Core\Kernel'];
            public const array COMPOSITION_ROOT_NAMESPACES = ['Pulsar\Core\Wiring\\'];
        }
        PHP;

    #[Test]
    public function itRefusesASecondCopyOfTheRuleInSource(): void
    {
        $this->plantAuthority();

        // The runtime guard, carrying its own idea of what a composition root is. This is
        // the copy that disagreed with the static checker about Pulsar\Core\Boot.
        $this->plant($this->tempDirectory, 'src/Extensibility/BoundaryGuard.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Extensibility;

            final class BoundaryGuard
            {
                private const array COMPOSITION_ROOTS = ['Pulsar\Core\Kernel', 'Pulsar\Core\Boot'];
            }
            PHP);

        $offenders = new SecondDefinitionScanner($this->tempDirectory)->offenders();

        self::assertNotSame(
            [],
            $offenders,
            'The authority rule stayed silent on a second declaration of the '
            . 'composition-root list. What ships when it stays silent is two rules wearing '
            . 'one name: the copies this repository already had disagreed about '
            . 'Pulsar\\Core\\Boot, so a class there satisfied the static boundary gate and '
            . 'was refused at runtime — a build that is green and a deployment that is not.',
        );
        self::assertStringContainsString('src/Extensibility/BoundaryGuard.php', implode(' ', $offenders));
    }

    /**
     * The copies were not all in src/. Two of the three were checkers under tools/ and
     * scripts/, which is why those trees are scanned at all.
     */
    #[Test]
    public function itRefusesACopyInTheCheckersThemselves(): void
    {
        $this->plantAuthority();

        $this->plant($this->tempDirectory, 'tools/ci/wiring-check.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            const COMPOSITION_ROOT_NAMESPACES = ['Pulsar\Core\Wiring\\'];

            final class WiringCheck
            {
                public const array COMPOSITION_ROOT_NAMESPACES = ['Pulsar\Core\Wiring\\'];
            }
            PHP);

        $offenders = new SecondDefinitionScanner($this->tempDirectory)->offenders();

        self::assertStringContainsString(
            'tools/ci/wiring-check.php',
            implode(' ', $offenders),
            'The rule scanned src/ and stopped. What ships when it stays silent is exactly '
            . 'the drift that happened: two of the three copies were the checkers, and a '
            . 'checker with its own private list enforces something the other two do not.',
        );
    }

    /**
     * The authority itself must not be reported, or the rule fails on a healthy repository
     * and is switched off within the day.
     */
    #[Test]
    public function itIsSilentOnTheAuthorityAndOnOrdinaryCode(): void
    {
        $this->plantAuthority();

        $this->plant($this->tempDirectory, 'src/Core/Kernel.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Core;

            use Pulsar\Api\CompositionRoots;

            final class Kernel
            {
                public function roots(): array
                {
                    return CompositionRoots::COMPOSITION_ROOTS;
                }
            }
            PHP);

        $scanner = new SecondDefinitionScanner($this->tempDirectory);

        self::assertNotSame([], $scanner->sources(), 'no sources scanned — the refusals above would be vacuous');
        self::assertSame(
            [],
            $scanner->offenders(),
            'the rule reported the authority itself, or a file that merely reads it — '
            . 'a rule that fires on the healthy case is a rule that gets deleted',
        );
    }

    private function plantAuthority(): void
    {
        $this->plant($this->tempDirectory, SecondDefinitionScanner::AUTHORITY, self::AUTHORITY);
    }
}
