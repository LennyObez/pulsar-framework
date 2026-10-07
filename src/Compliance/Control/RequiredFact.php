<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * One fact a control needs, what its absence means, and how to close it.
 *
 * A probe names these; it never names an outcome. The distinction is the seal:
 * `essential` and `contributing` are statements about how a fact RELATES to the
 * control, which a probe author genuinely knows, whereas Satisfied and
 * Unsatisfied are statements about a deployment, which only the gathered
 * evidence can settle.
 *
 * The remediation is mandatory here rather than checked later. A reported gap
 * that does not say how to close it is a complaint, not a finding, and requiring
 * it at the point the fact is declared means no branch of the decision table can
 * produce one.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RequiredFact
{
    use SealedValue;

    /**
     * @param string                           $whenMissing Optional framing for the absence.
     *        Empty is the normal case: the report then cites the observation's own detail, which
     *        says what stood there instead. Prose is worth adding only where two absences of the
     *        same control are not interchangeable and must not print the same way
     * @param non-empty-list<non-empty-string> $remediations
     */
    private function __construct(
        public ObservationId $id,
        public bool $essential,
        public string $whenMissing,
        public array $remediations,
    ) {}

    /**
     * A fact whose absence is a gap, whatever else was observed.
     *
     * For controls whose failure modes are not interchangeable: a PAN vault that
     * loses its mappings is not a weaker vault, it is an unrecoverable one, and
     * grading that Partial because the cryptography beside it was observed would
     * tell an operator to fix the wrong thing.
     *
     * @param list<non-empty-string> $remediations Typed as a plain list, not a non-empty
     *        one: this is public API, code outside this repository can call it, and the
     *        emptiness rule is therefore enforced below rather than only by a static
     *        analyser the caller may never run
     * @param string                 $whenMissing Framing for the absence; the observation's
     *        own detail is cited beside it either way
     *
     * @throws InadmissibleEvidenceException when no remediation is named
     */
    #[NoDiscard]
    public static function essential(ObservationId $id, array $remediations, string $whenMissing = ''): self
    {
        if ($remediations === []) {
            throw InadmissibleEvidenceException::forFactWithoutRemediation($id);
        }

        return new self($id, true, $whenMissing, $remediations);
    }

    /**
     * A fact the control needs, whose absence leaves the rest standing.
     *
     * Absent, the control is Partial when another required fact was observed
     * behaving, and a gap when none was.
     *
     * @param list<non-empty-string> $remediations Typed as a plain list, not a non-empty
     *        one: this is public API, code outside this repository can call it, and the
     *        emptiness rule is therefore enforced below rather than only by a static
     *        analyser the caller may never run
     * @param string                 $whenMissing Framing for the absence; the observation's
     *        own detail is cited beside it either way
     *
     * @throws InadmissibleEvidenceException when no remediation is named
     */
    #[NoDiscard]
    public static function contributing(ObservationId $id, array $remediations, string $whenMissing = ''): self
    {
        if ($remediations === []) {
            throw InadmissibleEvidenceException::forFactWithoutRemediation($id);
        }

        return new self($id, false, $whenMissing, $remediations);
    }
}
