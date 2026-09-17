<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What the platform underneath this process offers, read without using it.
 *
 * The only material from which {@see ObservationGrade::Available} can be
 * reached, and it exists because the vocabulary had no honest way to say
 * "ext-sodium is loaded". That sentence is not a measurement — nothing was put
 * through its work — and it is not a resolution either, because no class was
 * named: a deployment can carry libsodium and bind no cipher suite at all. It
 * was nonetheless being reported at grade {@see ObservationGrade::Measured},
 * where it carried nine controls across seven frameworks to Satisfied on
 * `extension_loaded('sodium')`.
 *
 * The shape mirrors {@see Measurement} deliberately, because the failure mode is
 * the same one:
 *
 *  - {@see offered()} needs at least one named primitive. "The platform offers
 *    cryptography" without saying WHICH function answered is the vacuous claim
 *    this whole design exists to make inexpressible, so it does not typecheck at
 *    runtime — it throws.
 *  - {@see absent()} is the platform having been asked and not having it.
 *  - {@see notInspected()} is nobody having asked. Kept apart from
 *    {@see absent()} for the reason {@see Measurement::couldNotRun()} is kept
 *    apart from a failed run: both observe absent, but only one of them is
 *    grounds for telling an operator to install something, and an operator sent
 *    to install an extension that is already installed will change nothing and
 *    see the same finding again.
 *
 * Whether the capability is OFFERED is not a parameter anywhere: it is derived
 * from which named constructor was used, and each of the three is a report of a
 * different thing that happened rather than a value of a flag. All three refuse
 * a caller outside `src/Compliance/Evidence/`; see {@see MeasuringComponent} for
 * what that seal does and does not stop. The constructor is private and the
 * value refuses deserialization, cloning and `var_export` round-tripping; see
 * {@see SealedValue}.
 *
 * WHAT THIS DOES NOT FIX, because the repair it belongs to does not fix it: an
 * observation at this grade still FILLS a required slot in
 * {@see ProbeVerdict::reach()}, which asks whether every required fact is
 * present before it asks whether any of them proves anything. So a control whose
 * only unmet requirement is a platform primitive still reads as claimed and not
 * observed rather than as partial. That test is grade-blind by design today and
 * is deferred to its own decision.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PlatformCapability
{
    use SealedValue;

    /**
     * @param string           $capability The primitive family, as a phrase
     * @param list<string>     $primitives What was found, named one by one
     * @param non-empty-string $detail     What the platform reported, for the report
     * @param bool             $inspected  Whether the platform was asked at all. Not stored:
     *        it exists so the emptiness rule below is a property of the value rather than of
     *        one factory, and an empty primitive list means two different things without it
     */
    private function __construct(
        public string $capability,
        public array $primitives,
        public bool $offered,
        public string $detail,
        bool $inspected,
    ) {
        // Checked here rather than only in offered(), so that "the platform offers
        // it, and nothing was named" is refused however the value is reached — by a
        // second factory added later, or by the Reflection escape the tests use.
        if ($inspected && $offered && $primitives === []) {
            throw UnmeasuredSubjectException::nothingNamed($capability);
        }
    }

    /**
     * The platform was asked and it has these primitives.
     *
     * @param string           $capability
     * @param list<string>     $primitives Typed as a plain list, not a non-empty one: this is
     *        public API, code outside this repository can call it, and the emptiness rule is
     *        therefore enforced in the constructor rather than only by a static analyser the
     *        caller may never run
     * @param non-empty-string $detail
     *
     * @throws UnmeasuredSubjectException    when the capability is claimed and nothing is named
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function offered(string $capability, array $primitives, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($capability, $primitives, true, $detail, inspected: true);
    }

    /**
     * The platform was asked and it does not have the capability.
     *
     * A real answer, and a bad one. Distinct from {@see notInspected()} because
     * this is the only one of the two that justifies telling an operator to
     * change their build.
     *
     * @param string           $capability
     * @param non-empty-string $why
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function absent(string $capability, string $why): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($capability, [], false, $why, inspected: true);
    }

    /**
     * Nobody asked the platform, and this is why.
     *
     * Reported rather than silently skipped, for the reason
     * {@see Measurement::couldNotRun()} is: a question that was never asked is a
     * different fact from one that was asked and answered badly, and neither is
     * a capability.
     *
     * @param string           $capability
     * @param non-empty-string $why
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function notInspected(string $capability, string $why): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($capability, [], false, $why, inspected: false);
    }
}
