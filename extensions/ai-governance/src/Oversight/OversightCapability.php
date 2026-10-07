<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Oversight;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * The capacities Article 14(4) requires human oversight to give a natural person.
 *
 * The five cases are the Article's own five points, in its own order, and nothing
 * else. Article 14(4) says the measures shall be such as to ENABLE the persons to
 * whom oversight is assigned to do these things, so the list is a list of
 * capacities and not of good intentions.
 *
 * THE CASES SPLIT INTO TWO KINDS, and the split is what makes this enum decide
 * something rather than describe something. Points (a), (b) and (c) — understand
 * the system's capacities and limitations, remain alert to automation bias,
 * correctly interpret the output — are properties of the PERSON and of the
 * training and instructions they were given. No framework can observe them, and
 * one that claimed to would be scoring a human being's comprehension from a
 * database row. Points (d) and (e) — disregard, override or reverse the output,
 * and intervene or interrupt the operation — are properties of the SYSTEM: either
 * a control exists that lets a person do that, or none does, and that is a fact
 * about a deployment rather than about a person.
 *
 * So {@see mustBeExercisable()} answers true for exactly (d) and (e), and
 * {@see \Pulsar\Extension\AiGovernance\Oversight\OversightAssignment} refuses to
 * exist unless both are among the capacities the assignment confers. An oversight
 * arrangement whose overseer cannot stop the system is not oversight; it is
 * observation, and Article 14 asks for the first.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum OversightCapability: string
{
    /**
     * Article 14(4)(a): properly understand the relevant capacities and
     * limitations of the system and duly monitor its operation, including to
     * detect and address anomalies, dysfunctions and unexpected performance.
     */
    case UnderstandAndMonitor = 'understand_and_monitor';

    /**
     * Article 14(4)(b): remain aware of the possible tendency of automatically
     * relying or over-relying on the output — automation bias — in particular for
     * systems used to provide information or recommendations for a decision taken
     * by natural persons.
     */
    case RemainAwareOfAutomationBias = 'remain_aware_of_automation_bias';

    /**
     * Article 14(4)(c): correctly interpret the system's output, taking into
     * account the interpretation tools and methods available.
     */
    case CorrectlyInterpretOutput = 'correctly_interpret_output';

    /**
     * Article 14(4)(d): decide, in any particular situation, not to use the
     * high-risk AI system, or otherwise disregard, override or reverse its output.
     */
    case DisregardOrReverseOutput = 'disregard_or_reverse_output';

    /**
     * Article 14(4)(e): intervene in the operation of the system or interrupt it
     * through a stop button or a similar procedure that allows it to come to a
     * halt in a safe state.
     */
    case InterveneOrInterrupt = 'intervene_or_interrupt';

    /**
     * Whether this capacity is a property of the system rather than of the person.
     *
     * True for the two points that name an ACTION a person takes against the
     * running system, which either has a control for it or does not. False for
     * the three that name a state of understanding, which is conferred by
     * training and instructions and cannot be read out of a record.
     */
    #[NoDiscard]
    public function mustBeExercisable(): bool
    {
        return match ($this) {
            self::DisregardOrReverseOutput, self::InterveneOrInterrupt => true,
            self::UnderstandAndMonitor,
            self::RemainAwareOfAutomationBias,
            self::CorrectlyInterpretOutput => false,
        };
    }

    /**
     * The capacities an assignment must confer for oversight to be effective.
     *
     * @return non-empty-list<self>
     */
    #[NoDiscard]
    public static function exercisable(): array
    {
        return [self::DisregardOrReverseOutput, self::InterveneOrInterrupt];
    }
}
