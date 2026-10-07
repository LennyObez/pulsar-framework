<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Oversight;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What an overseer actually did, when oversight was exercised.
 *
 * The four cases are the four actions Article 14(4)(d) and (e) name, kept apart
 * rather than folded into one "intervened" case, because they are different
 * events with different consequences. Declining to use the system leaves no
 * output at all. Disregarding one leaves an output that was produced and not
 * acted on. Reversing one leaves a decision that took effect and was undone,
 * which is the case a person affected by it is most likely to contest. Halting
 * the system stops it producing anything further.
 *
 * An assessor reading a register that recorded only "intervened" could not tell
 * whether a system had ever been stopped, and Article 14(4)(e) asks specifically
 * about the ability to stop it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum OversightAction: string
{
    /** Article 14(4)(d): decided, in this situation, not to use the system at all. */
    case DeclinedToUse = 'declined_to_use';

    /** Article 14(4)(d): the output was produced, and was not acted on. */
    case DisregardedOutput = 'disregarded_output';

    /** Article 14(4)(d): the output had taken effect, and was undone. */
    case ReversedOutput = 'reversed_output';

    /** Article 14(4)(e): the operation was interrupted and brought to a safe halt. */
    case InterruptedOperation = 'interrupted_operation';

    /**
     * The Article 14(4) capacity this action exercises.
     *
     * An intervention is evidence of a capacity only if it maps to one, which is
     * what lets a report say that the ability Article 14(4)(e) asks for has been
     * used rather than merely conferred on paper.
     */
    #[NoDiscard]
    public function exercises(): OversightCapability
    {
        return match ($this) {
            self::DeclinedToUse,
            self::DisregardedOutput,
            self::ReversedOutput => OversightCapability::DisregardOrReverseOutput,
            self::InterruptedOperation => OversightCapability::InterveneOrInterrupt,
        };
    }
}
