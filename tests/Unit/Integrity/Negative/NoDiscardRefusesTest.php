<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Fixture\Attributes\NamesWithoutSayingSo;
use Pulsar\Tests\Unit\Integrity\Fixture\Attributes\SaysTheResultMatters;
use Pulsar\Tests\Unit\Integrity\Support\NoDiscardScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\ReflectedClassIndex;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function dirname;
use function implode;

use const DIRECTORY_SEPARATOR;

/**
 * The #[NoDiscard] rules, watched refusing — and one of them watched being unable to.
 *
 * Two rules ship under this name and they are not the same kind of thing.
 *
 * The first, "#[NoDiscard] must not sit on a void method", cannot fail. PHP 8.5 rejects
 * that combination at compile time with an uncatchable fatal, so no fixture, no temp file
 * and no eval() can produce a class in the state the rule looks for. The rule agrees with
 * the engine by construction. That is worth recording rather than papering over, because
 * a rule that cannot fail and a rule that is passing are indistinguishable from the
 * outside — which is the whole finding this work exists to close. So the compile-time
 * refusal is asserted directly, as the reason the other kind of test is impossible here.
 *
 * The second rule is real: a `with*` method on a readonly class that does not say its
 * result must be used. Dropping that result is a silent no-op — the caller believes it
 * changed something and nothing changed — and the fixture beside this file violates it
 * on purpose.
 */
#[CoversClass(NoDiscardScanner::class)]
#[CoversClass(ReflectedClassIndex::class)]
#[GuardsGate(
    gate: 'NoDiscardCorrectnessTest::with_methods_on_readonly_classes_have_no_discard',
    plants: 'a readonly fixture class whose withLabel() returns self and carries no attribute',
)]
#[GuardsGate(
    gate: 'NoDiscardCorrectnessTest::no_discard_methods_return_non_void',
    plants: 'the void-returning defect, in a subprocess, to establish that PHP refuses to compile it at all',
)]
final class NoDiscardRefusesTest extends FilesystemTestCase
{
    use InvokesCiScript;
    use PlantsFiles;

    #[Test]
    public function itRefusesAnImmutableModifierThatDoesNotSayItsResultMatters(): void
    {
        $missing = $this->scanner()->withMethodsMissingNoDiscard();

        self::assertContains(
            NamesWithoutSayingSo::class . '::withLabel()',
            $missing,
            'The #[NoDiscard] rule stayed silent on a with*() method of a readonly class '
            . 'that never says its result must be used. What ships when it stays silent is '
            . 'an immutable API whose most obvious misuse is invisible: `$x->withLabel($l);` '
            . 'on its own line compiles, runs, warns about nothing and changes nothing, and '
            . 'the caller has no way to find out. It reported: [' . implode(', ', $missing) . ']',
        );
    }

    /**
     * The annotated twin must not be reported, or the refusal above says nothing.
     */
    #[Test]
    public function itIsSilentOnTheModifierThatDoesSaySo(): void
    {
        $missing = $this->scanner()->withMethodsMissingNoDiscard();

        self::assertNotContains(SaysTheResultMatters::class . '::withLabel()', $missing);
        self::assertNotContains(
            'Pulsar\\Tests\\Unit\\Integrity\\Fixture\\Attributes\\MutatesInPlace::withLabel()',
            $missing,
            'the rule reported a mutable builder, which loses nothing when its result is '
            . 'dropped — over-reporting is how a rule earns being switched off',
        );
    }

    /**
     * The scan must see the fixture tree at all.
     */
    #[Test]
    public function itReadsTheFixtureTreeItIsBeingAskedAbout(): void
    {
        $annotated = $this->scanner()->noDiscardMethods();

        self::assertNotSame([], $annotated, 'no #[NoDiscard] methods found — every silence above is vacuous');
        self::assertSame([], $this->scanner()->voidMethodsCarryingNoDiscard());
    }

    /**
     * The void rule, established as unplantable rather than left as a claim.
     *
     * The subprocess is the only way to observe this: the failure is a fatal error at
     * compile time, so it cannot be caught, and a `require` of such a file would take this
     * test process down with it.
     */
    #[Test]
    public function phpItselfRefusesTheVoidDefectSoTheRuleCannotBeGivenOne(): void
    {
        $defect = $this->plant($this->tempDirectory, 'void-no-discard.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            final class ReturnsNothingButDemandsUse
            {
                #[\NoDiscard]
                public function nothing(): void
                {
                }
            }

            echo "loaded\n";
            PHP);

        [$status, $stdout, $stderr] = $this->runScript($defect);

        self::assertNotSame(
            0,
            $status,
            'PHP loaded a class carrying #[NoDiscard] over a void return. If this ever '
            . 'passes, NoDiscardCorrectnessTest::no_discard_methods_return_non_void has '
            . 'become a real gate and needs a planted fixture like the one above it — '
            . 'until then it is a rule the engine already enforces, and counting it as '
            . 'coverage of anything would be counting the same check twice.',
        );
        self::assertStringNotContainsString('loaded', $stdout, 'the file executed, so it compiled');
        self::assertStringContainsString(
            'NoDiscard',
            $stdout . $stderr,
            'the process failed for some reason other than the attribute, so this proves nothing',
        );
    }

    private function scanner(): NoDiscardScanner
    {
        return new NoDiscardScanner(new ReflectedClassIndex(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixture' . DIRECTORY_SEPARATOR . 'Attributes',
            'Pulsar\\Tests\\Unit\\Integrity\\Fixture\\Attributes\\',
        ));
    }
}
