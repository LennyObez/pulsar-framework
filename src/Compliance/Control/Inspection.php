<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function count;
use function in_array;

/**
 * The record of a live population the assessor enumerated and judged.
 *
 * The second material from which {@see ObservationGrade::Resolved} can be
 * reached — the first being {@see ContractResolution}, which answers for one
 * binding. This answers for a set: the routes the router will dispatch, the
 * extensions that booted, the optional bindings a wiring contract declared.
 *
 * Whether the fact holds is computed from the enumeration in every case. No
 * constructor takes it, and {@see coverage()} additionally refuses an empty
 * population, because "every classified route carries its middleware" over zero
 * classified routes prints identically to real coverage and is the vacuous pass
 * this whole design exists to make inexpressible.
 *
 * THAT ARITHMETIC IS ONLY AS GOOD AS ITS INPUT, and review said so about
 * {@see membership()} in particular: `in_array($member, $roster)` over a roster
 * the caller wrote is a boolean with extra steps. It is exactly as invented as
 * the roster is, and a signature cannot tell an enumeration from a literal. So
 * every named constructor here refuses a caller outside
 * `src/Compliance/Evidence/` — see {@see MeasuringComponent} — where the rosters
 * come from the router, the extension registry and the wiring inspector rather
 * than from an argument list. The constructor is private and the value refuses
 * deserialization, cloning and `var_export`; see {@see SealedValue}.
 *
 * None of these can carry a control any more in any case: they are graded
 * {@see ObservationGrade::Resolved}, and Resolved stopped proving behaviour. What
 * an inspection decides now is how a fact READS in the report.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class Inspection
{
    use SealedValue;

    /**
     * @param string           $population
     * @param non-empty-string $detail
     * @param int|null         $enumerated How many members were enumerated, or null where the
     *        factory concluded over something other than a population. Not stored: it exists
     *        so the refusal below is a property of the value rather than of one factory
     */
    private function __construct(
        public string $population,
        public bool $holds,
        public string $detail,
        ?int $enumerated = null,
    ) {
        // Checked here rather than only in coverage(), so that a conclusion drawn
        // over an enumerated-and-empty estate is refused however the value is
        // reached. Null means the factory enumerated nothing to count: a defect
        // scan answers over its own findings and nothingToInspect() reports the
        // absence of a population rather than concluding over one.
        if ($enumerated === 0) {
            throw UnmeasuredSubjectException::emptyPopulation($population);
        }
    }

    /**
     * Every member of a population was checked against what it must carry.
     *
     * Holds when nothing fell short — over a population that exists.
     *
     * @param string           $population
     * @param list<string>     $members    What was enumerated. Typed as a plain list, not a
     *        non-empty one: this is public API, code outside this repository can call it, and
     *        the emptiness rule is therefore enforced below rather than only by a static
     *        analyser the caller may never run
     * @param list<string>     $shortfall  The members that did not carry it
     * @param non-empty-string $detail
     *
     * @throws UnmeasuredSubjectException     when the population is empty
     * @throws InadmissibleEvidenceException when the caller is not the component that
     *         measures this deployment
     */
    #[NoDiscard]
    public static function coverage(string $population, array $members, array $shortfall, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($population, $shortfall === [], $detail, count($members));
    }

    /**
     * A search for a specific defect across a live population.
     *
     * Holds when the search found none. The inverse of {@see coverage()}: here
     * the empty list is the good answer, because the list IS the defects, and an
     * inspector that reports no degraded feature has answered rather than
     * declined to look. Reserved for facts whose material is an inspector's own
     * defect list — never for a population that simply was not enumerated, which
     * is {@see nothingToInspect()}.
     *
     * @param string           $population
     * @param list<string>     $defects
     * @param non-empty-string $detail
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function defectScan(string $population, array $defects, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($population, $defects === [], $detail);
    }

    /**
     * A roster was read and asked whether it contains one member.
     *
     * Holds when it does. The roster is the material; membership is derived from
     * it rather than reported about it.
     *
     * @param string           $population
     * @param string           $member
     * @param list<string>     $roster
     * @param non-empty-string $detail
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function membership(string $population, string $member, array $roster, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($population, in_array($member, $roster, true), $detail);
    }

    /**
     * There was no population to enumerate, and this is why.
     *
     * Never holds. A deployment that classified no route, or resolved no profile
     * to judge one against, has not passed the check — it has not taken it.
     *
     * @param string           $population
     * @param non-empty-string $why
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function nothingToInspect(string $population, string $why): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($population, false, $why);
    }
}
