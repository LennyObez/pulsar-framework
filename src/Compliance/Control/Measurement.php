<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * The record of something the assessor actually ran.
 *
 * The only material from which {@see ObservationGrade::Measured} can be reached.
 * Its two named constructors are reports of what happened, not values of a flag:
 *
 *  - {@see completed()} needs at least one {@see ExecutedSubject}. There is no
 *    path through it that names nothing, so "measured, and nothing was measured"
 *    is not a weak claim here — it does not typecheck at runtime, it throws.
 *  - {@see couldNotRun()} is how absence is reported. It always observes absent,
 *    so a missing runner, a thrown exception and an empty registry can never be
 *    mistaken for a clean result.
 *
 * Whether the fact HOLDS is not a parameter anywhere: it is computed from the
 * subjects that ran. A caller cannot hand this object an opinion — and, since the
 * seal, a caller outside `src/Compliance/Evidence/` cannot hand it anything at
 * all. Both named constructors refuse a foreign caller; see
 * {@see MeasuringComponent}. The constructor is private and the value refuses
 * deserialization, cloning and `var_export` round-tripping; see
 * {@see SealedValue}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class Measurement
{
    use SealedValue;

    /**
     * @param string                $subject
     * @param list<ExecutedSubject> $results
     * @param non-empty-string      $detail
     * @param bool                  $ran     Whether a run happened at all. Not stored: it
     *        exists so the emptiness rule below is a property of the value rather than of
     *        one factory, and an empty result set means two different things without it
     */
    private function __construct(
        public string $subject,
        public array $results,
        public string $detail,
        bool $ran,
    ) {
        // Checked here rather than only in completed(), so that "measured, and
        // nothing was measured" is refused however the value is reached — by a
        // second factory added later, or by the Reflection escape the tests use.
        if ($ran && $results === []) {
            throw UnmeasuredSubjectException::nothingRan($subject);
        }
    }

    /**
     * The run finished and these subjects answered.
     *
     * @param string                $subject What was exercised, as a phrase
     * @param list<ExecutedSubject> $results Typed as a plain list, not a non-empty one:
     *        this is public API, code outside this repository can call it, and the emptiness
     *        rule is therefore enforced below rather than only by a static analyser the
     *        caller may never run
     * @param non-empty-string      $detail  What the run showed, for the report
     *
     * @throws UnmeasuredSubjectException     when nothing is named as having run
     * @throws InadmissibleEvidenceException when the caller is not the component that
     *         measures this deployment
     */
    #[NoDiscard]
    public static function completed(string $subject, array $results, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($subject, $results, $detail, ran: true);
    }

    /**
     * The run did not happen, and this is why.
     *
     * Reported rather than silently skipped: a check that never executed is a
     * different fact from one that executed and passed, and only one of the two
     * is evidence.
     *
     * @param string           $subject
     * @param non-empty-string $why
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function couldNotRun(string $subject, string $why): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($subject, [], $why, ran: false);
    }

    /**
     * Whether every subject that ran returned the desirable answer.
     *
     * False for a run that did not happen: {@see couldNotRun()} leaves the result
     * set empty, and this method reads an empty set as "nothing answered", never
     * as "nothing objected".
     */
    #[NoDiscard]
    public function succeeded(): bool
    {
        if ($this->results === []) {
            return false;
        }

        foreach ($this->results as $result) {
            if (!$result->passed) {
                return false;
            }
        }

        return true;
    }
}
