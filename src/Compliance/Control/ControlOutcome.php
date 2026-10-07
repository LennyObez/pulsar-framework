<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What a probe concluded.
 *
 * Replaces the deleted `ControlStatus`. The names changed on purpose:
 * "Implemented" is a statement about a source tree; these are statements about
 * a deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ControlOutcome: string
{
    /** Observed working in this deployment. */
    case Satisfied = 'satisfied';

    /** Partly observed; the residual gap is named in the verdict's remediations. */
    case Partial = 'partial';

    /** An enabled framework's mapping claims it and the deployment does not show it. */
    case Unsatisfied = 'unsatisfied';

    /** Out of scope, on an operator assertion reproduced with its config key. */
    case NotApplicable = 'not_applicable';

    /** Discharged outside the software; never counted as coverage. */
    case OperatorResponsibility = 'operator_responsibility';

    #[NoDiscard]
    public function isGap(): bool
    {
        return $this === self::Unsatisfied;
    }

    /**
     * Whether the outcome contributes to the coverage denominator at all.
     *
     * OperatorResponsibility does not: the framework has no standing to judge a
     * policy document, and letting such controls into the arithmetic is how a
     * coverage percentage is inflated without a single false claim being made.
     * NotApplicable does not either — it leaves the denominator on a recorded
     * operator assertion rather than on anything the framework observed.
     */
    #[NoDiscard]
    public function countsTowardCoverage(): bool
    {
        return $this !== self::OperatorResponsibility && $this !== self::NotApplicable;
    }

    /**
     * Worst-first ordering rank for the report, so the actionable part is read
     * without scrolling. Lower sorts earlier.
     */
    #[NoDiscard]
    public function severityRank(): int
    {
        return match ($this) {
            self::Unsatisfied => 0,
            self::Partial => 1,
            self::Satisfied => 2,
            self::NotApplicable => 3,
            self::OperatorResponsibility => 4,
        };
    }
}
