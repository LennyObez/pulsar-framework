<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Fixture\Attributes\HandlesRequests;
use Pulsar\Tests\Unit\Integrity\Fixture\Attributes\HandlesRequestsDifferently;
use Pulsar\Tests\Unit\Integrity\Support\OverrideScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\ReflectedClassIndex;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function dirname;

use const DIRECTORY_SEPARATOR;

/**
 * The #[Override] rule, and the reason it cannot be watched refusing.
 *
 * Writing this test is what established that OverrideCorrectnessTest's main assertion
 * cannot fail, which is a more useful result than a fixture would have been. PHP will not
 * compile a method carrying #[\Override] that overrides nothing:
 *
 *     Fatal error: C::m() has #[\Override] attribute, but no matching parent method exists
 *
 * That is a compile-time fatal, so it happens before any test body runs and cannot be
 * caught — not through require, not through eval(). There is no way to hand the rule the
 * defect it looks for. The engine is the gate, and the assertion in src/ agrees with it
 * by construction rather than by checking anything.
 *
 * Recorded here rather than hidden, because "this gate cannot fail" is exactly the fact
 * that nine gates in this repository were sitting on. Two things are asserted instead:
 * that PHP really does refuse (so the claim is measured, not remembered), and that the
 * one piece of the rule PHP does not supply — the predicate deciding whether a method
 * overrides anything — answers false where it must. A predicate that said "yes" to
 * everything would silence this rule even on a PHP that allowed the annotation.
 */
#[CoversClass(OverrideScanner::class)]
#[GuardsGate(
    gate: 'OverrideCorrectnessTest::all_override_methods_actually_override',
    plants: 'the stale-annotation defect in a subprocess, establishing that PHP refuses to compile it; and a non-overriding method fed to the predicate the rule computes itself',
)]
final class OverrideRefusesTest extends FilesystemTestCase
{
    use InvokesCiScript;
    use PlantsFiles;

    /**
     * The predicate, given a method that overrides nothing.
     *
     * `alsoOnlyHere()` and `handle()` sit in the same class with the same visibility. The
     * only difference between them is the thing the rule claims to check, so a predicate
     * that has stopped discriminating fails here.
     */
    #[Test]
    public function itsPredicateRefusesAMethodThatOverridesNothing(): void
    {
        self::assertFalse(
            OverrideScanner::overridesSomething(HandlesRequestsDifferently::class, 'alsoOnlyHere'),
            'The predicate behind the #[Override] rule said a method overrides something '
            . 'when nothing above it declares that name. What ships when it answers that '
            . 'way is a rule that reports no stale annotations because it can no longer '
            . 'tell a stale one from a live one — silence that reads exactly like health.',
        );

        self::assertFalse(
            OverrideScanner::overridesSomething(HandlesRequests::class, 'handle'),
            'A method on a class with no parent and no interface cannot be overriding anything.',
        );
    }

    /**
     * And answers true where it must, or the refusal above is "it says no to everything".
     */
    #[Test]
    public function itsPredicateAcceptsAGenuineOverride(): void
    {
        self::assertTrue(OverrideScanner::overridesSomething(HandlesRequestsDifferently::class, 'handle'));
        self::assertTrue(OverrideScanner::overridesSomething(HandlesRequestsDifferently::class, 'onlyHere'));
    }

    /**
     * The collection half: the rule must find the annotation before it can judge it.
     */
    #[Test]
    public function itCollectsTheAnnotationsItIsAskedToJudge(): void
    {
        $scanner = new OverrideScanner(new ReflectedClassIndex(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixture' . DIRECTORY_SEPARATOR . 'Attributes',
            'Pulsar\\Tests\\Unit\\Integrity\\Fixture\\Attributes\\',
        ));

        self::assertContains(
            ['class' => HandlesRequestsDifferently::class, 'method' => 'handle'],
            $scanner->overrideMethods(),
            'the rule found no #[Override] in a tree that contains one, so its verdict on '
            . 'src/ is a verdict over nothing',
        );

        // Empty by construction, for the reason the class docblock gives.
        self::assertSame([], $scanner->staleOverrides());
    }

    /**
     * The compile-time refusal, measured.
     *
     * A subprocess is the only way to observe it: a `require` of this file would take the
     * test process down, and the error is not throwable.
     */
    #[Test]
    public function phpItselfRefusesTheDefectSoTheRuleCannotBeGivenOne(): void
    {
        $defect = $this->plant($this->tempDirectory, 'stale-override.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            final class OverridesNothingAtAll
            {
                #[\Override]
                public function handle(string $request): string
                {
                    return $request;
                }
            }

            echo "loaded\n";
            PHP);

        [$status, $stdout, $stderr] = $this->runScript($defect);

        self::assertNotSame(
            0,
            $status,
            'PHP loaded a class whose #[Override] overrides nothing. If this ever passes, '
            . 'OverrideCorrectnessTest::all_override_methods_actually_override has become a '
            . 'real gate and needs a planted fixture; until then it is a second opinion on '
            . 'a rule the engine enforces, and must not be counted as coverage of anything.',
        );
        self::assertStringNotContainsString('loaded', $stdout, 'the file executed, so it compiled');
        self::assertStringContainsString(
            'Override',
            $stdout . $stderr,
            'the process failed for some reason other than the attribute, so this proves nothing',
        );
    }
}
