<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\GateCoverageIndex;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;

use function array_diff;
use function array_merge;
use function array_values;
use function implode;

/**
 * The gate that guards the gates, watched refusing.
 *
 * GateNegativeCoverageTest exists because a check nobody has watched fail is
 * indistinguishable from no check. It is itself a check, so exempting it from its own
 * proposition would be the finding wearing a different hat — and it would be the easiest
 * possible place for the whole exercise to quietly stop working, because the symptom of
 * this one breaking is that everything looks covered.
 *
 * So a repository is assembled here that contains a ratchet nobody has guarded, and the
 * enumeration is watched naming it. Then the two ways it could be fooled into silence are
 * planted as well: a docblock that mentions the attribute, and an exemption for a rule
 * that no longer exists.
 */
#[CoversClass(GateCoverageIndex::class)]
#[GuardsGate(
    gate: 'GateNegativeCoverageTest::everyRatchetRuleHasBeenWatchedRefusing',
    plants: 'a fixture repository holding a ratchet whose rule no #[GuardsGate] anywhere claims',
)]
#[GuardsGate(
    gate: 'GateNegativeCoverageTest::theExemptionsAllNameRulesThatStillExist',
    plants: 'an exemption naming a rule the fixture repository does not contain',
)]
final class GateNegativeCoverageRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    #[Test]
    public function itRefusesARatchetNobodyHasGuarded(): void
    {
        $this->plantGuardedRatchet();

        // The defect: a new ratchet, added next month, with no negative test. It reads
        // the repository root, so it is a gate on the merge — and its silence is a claim
        // about the whole tree that nobody has ever seen it decline to make.
        $this->plantRatchet('LicenceHeaderRatchetTest', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity;

            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            final class LicenceHeaderRatchetTest extends TestCase
            {
                #[Test]
                public function everySourceFileCarriesTheLicenceHeader(): void
                {
                    $root = dirname(__DIR__, 3);
                    self::assertNotSame('', $root);
                }
            }
            PHP);

        $unguarded = $this->unguarded();

        self::assertContains(
            'LicenceHeaderRatchetTest::everySourceFileCarriesTheLicenceHeader',
            $unguarded,
            'The enumeration stayed silent on a ratchet nobody has watched refusing. What '
            . 'ships when it stays silent is the decay this whole exercise was built to '
            . 'stop: the negative tests written today cover the gates that exist today, and '
            . 'every gate added after them is unguarded by default — which is how nine '
            . 'gates in this repository came to be green and inert at the same time. '
            . 'It reported: [' . implode(', ', $unguarded) . ']',
        );
    }

    /**
     * A docblock that mentions the attribute is not a declaration.
     *
     * This is not hypothetical: the first version of GateCoverageIndex matched the
     * attribute as text, and two rules read as covered because two comments named them —
     * one of those comments being the failure message of the test that consumes the index.
     * It is the same defect QaCiParityTest refuses one level up, where a `run:` comment
     * mentioning a command must not count as running it.
     */
    #[Test]
    public function itRefusesARatchetWhoseOnlyCoverageIsAMentionInProse(): void
    {
        $this->plantGuardedRatchet();

        $this->plantRatchet('PreloadRatchetTest', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity;

            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            /**
             * Somebody wrote the claim instead of the test.
             *
             * Guarded by #[GuardsGate(gate: 'PreloadRatchetTest::thePreloadListIsComplete')]
             * over in the Negative directory. It is not, and this sentence is the only place
             * that name appears.
             */
            final class PreloadRatchetTest extends TestCase
            {
                #[Test]
                public function thePreloadListIsComplete(): void
                {
                    $root = dirname(__DIR__, 3);
                    self::assertNotSame('', $root);
                }
            }
            PHP);

        // The same string, this time inside an ordinary PHP string literal rather than a
        // comment — the other half of the same trap.
        $this->plant($this->tempDirectory, 'tests/Unit/Integrity/Negative/NotesTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity\Negative;

            final class NotesTest
            {
                public function hint(): string
                {
                    return "add #[GuardsGate(gate: 'PreloadRatchetTest::thePreloadListIsComplete')]";
                }
            }
            PHP);

        $unguarded = $this->unguarded();

        self::assertContains(
            'PreloadRatchetTest::thePreloadListIsComplete',
            $unguarded,
            'The enumeration accepted a mention of the attribute in a comment and in a '
            . 'string literal as a declaration that the gate had been watched refusing. '
            . 'What ships when it does is a coverage claim satisfiable by writing about it: '
            . 'the index would be measuring how often the attribute is spelled, not how '
            . 'often a defect was planted. It reported: [' . implode(', ', $unguarded) . ']',
        );
    }

    /**
     * The healthy case: a ratchet with a real declaration must not be reported, or every
     * refusal above only means "it reports everything".
     */
    #[Test]
    public function itIsSilentOnARatchetThatHasBeenWatchedRefusing(): void
    {
        $this->plantGuardedRatchet();

        $index = $this->index();

        self::assertSame(['SchemaDriftRatchetTest::theSchemaMatchesTheMigrations'], $index->rules());
        self::assertSame(['SchemaDriftRatchetTest::theSchemaMatchesTheMigrations'], $index->declared());
        self::assertSame([], $this->unguarded());
    }

    /**
     * An ordinary unit test in the same directory is not a gate, and treating it as one
     * would make the enumeration demand negative tests for things that have no defect to
     * plant — which is how a rule earns being switched off.
     */
    #[Test]
    public function itDoesNotMistakeAnOrdinaryUnitTestForARatchet(): void
    {
        $this->plantGuardedRatchet();

        $this->plantRatchet('ManifestEntryTest', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity;

            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            final class ManifestEntryTest extends TestCase
            {
                #[Test]
                public function itKeepsTheDigestItWasGiven(): void
                {
                    self::assertSame('abc', 'abc');
                }
            }
            PHP);

        $files = $this->index()->ratchetFiles();

        self::assertCount(1, $files, 'a unit test that reads no repository root was counted as a gate');
        self::assertStringContainsString('SchemaDriftRatchetTest', implode(' ', $files));
    }

    #[Test]
    public function itRefusesAnExemptionForARuleThatNoLongerExists(): void
    {
        $this->plantGuardedRatchet();

        // The rule this exemption names was deleted, renamed, or never existed. The
        // exemption stays, and the next rule to be written under that name inherits it.
        $exemptions = ['SchemaDriftRatchetTest::theRuleThatWasDeletedLastQuarter'];

        $stale = array_values(array_diff($exemptions, $this->index()->rules()));

        self::assertSame(
            $exemptions,
            $stale,
            'The staleness guard stayed silent on an exemption naming a rule that does not '
            . 'exist. What ships when it stays silent is an exemption list that only grows '
            . 'and stops being read — and, worse than a stale allowlist elsewhere, the next '
            . 'gate written under that name is excused from ever being watched refusing, '
            . 'before anybody has looked at it.',
        );
    }

    /**
     * A ratchet with a genuine negative test, present in every fixture so that the
     * assertions above are about the defect rather than about an empty repository.
     */
    private function plantGuardedRatchet(): void
    {
        $this->plantRatchet('SchemaDriftRatchetTest', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity;

            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            final class SchemaDriftRatchetTest extends TestCase
            {
                #[Test]
                public function theSchemaMatchesTheMigrations(): void
                {
                    $root = dirname(__DIR__, 3);
                    self::assertNotSame('', $root);
                }
            }
            PHP);

        $this->plant($this->tempDirectory, 'tests/Unit/Integrity/Negative/SchemaDriftRefusesTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Tests\Unit\Integrity\Negative;

            use PHPUnit\Framework\Attributes\Test;
            use Pulsar\Tests\Support\Gates\GuardsGate;

            #[GuardsGate(
                gate: 'SchemaDriftRatchetTest::theSchemaMatchesTheMigrations',
                plants: 'a migration set whose result differs from the committed schema',
            )]
            final class SchemaDriftRefusesTest
            {
                #[Test]
                public function itRefusesADriftedSchema(): void
                {
                }
            }
            PHP);
    }

    private function plantRatchet(string $className, string $source): void
    {
        $this->plant($this->tempDirectory, 'tests/Unit/Integrity/' . $className . '.php', $source);
    }

    /**
     * @return list<string>
     */
    private function unguarded(): array
    {
        $index = $this->index();

        return array_values(array_diff(
            $index->rules(),
            array_merge($index->declared(), $index->selfCovering()),
        ));
    }

    private function index(): GateCoverageIndex
    {
        return new GateCoverageIndex($this->tempDirectory);
    }
}
