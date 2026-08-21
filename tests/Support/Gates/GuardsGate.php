<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Gates;

use Attribute;

/**
 * Declares that this test is the negative test for a named gate rule.
 *
 * A gate that has never been observed to fail is indistinguishable from no gate. Nine of
 * them were found in this repository at once, each broken differently, each green: a
 * script that exited before checking anything, a ruleset no workflow ran, a benchmark
 * asserting a budget over zero work. What they had in common was not a bug — it was that
 * nobody had ever watched them refuse.
 *
 * So every gate rule carries a test that plants the defect it exists to catch and
 * observes the refusal, and this attribute is how that claim becomes machine-readable.
 * The mapping lives on the test rather than in a list beside it, because a list beside it
 * is a second definition of the same fact and the two drift — which is precisely the
 * failure `CompositionRootsAuthorityTest` exists to prevent, one level down.
 *
 * `GateNegativeCoverageTest` reads these and fails when a rule has none.
 *
 * Usable on a class (the common case: a whole `*RefusesTest`) or on a single method,
 * which is what a negative test living inside the ratchet it guards needs — the
 * `fixture_detects_*` methods of ArchitectureRulesTest are the precedent. A `#[Test]`
 * method carrying this attribute is itself a negative test and needs no other.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class GuardsGate
{
    /**
     * @param string $gate   The rule this test refuses on behalf of, as
     *                       `ShortTestClassName::methodName`. Exact, because a
     *                       description cannot be checked and a name can.
     * @param string $plants The defect the test constructs, in the words a reviewer would
     *                       use to confirm it is the defect the rule is for.
     */
    public function __construct(
        public string $gate,
        public string $plants,
    ) {}
}
