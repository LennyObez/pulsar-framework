<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * One thing that was actually run, and what it returned.
 *
 * The unit a {@see Measurement} is built from. It exists so that "measured" has
 * a floor: a measurement is a non-empty set of these, so there is no way to
 * reach {@see ObservationGrade::Measured} while naming nothing that ran.
 *
 * BE HONEST ABOUT THE SIGNATURE. {@see passed()} and {@see failed()} take two
 * strings and no proof of anything, and review said so plainly: they are a
 * caller-supplied boolean spelled as two method names. That criticism is correct
 * and cannot be answered by narrowing the parameters, which is what two earlier
 * versions of this class tried. Whatever the parameters are, the code that runs a
 * check has to report what the check returned, and no type can compare that
 * report against reality.
 *
 * What CAN be settled is who is allowed to make the report. Both methods refuse
 * every caller that is not the component that measures — see
 * {@see MeasuringComponent} — so composing a passing subject is not available to
 * a probe, a mapping, an extension, an application or a test. The boolean is
 * still supplied by a caller; there is now exactly one caller, it is fifty files
 * of `src/Compliance/Evidence/` that a reviewer can read end to end, and every
 * observation it produces prints the class that made it beside its grade.
 *
 * The constructor is private and the value refuses to be deserialised, cloned or
 * exported back into existence: see {@see SealedValue}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ExecutedSubject
{
    use SealedValue;

    /**
     * @param string           $name   What ran — a check id, a record range, a session
     * @param non-empty-string $detail What it returned, in the assessor's words
     */
    private function __construct(
        public string $name,
        public bool $passed,
        public string $detail,
    ) {}

    /**
     * The subject ran and returned the desirable answer.
     *
     * @param string           $name
     * @param non-empty-string $detail
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function passed(string $name, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($name, true, $detail);
    }

    /**
     * The subject ran and returned the undesirable answer.
     *
     * A failed subject is still a measurement: something was exercised and it
     * answered. That is why it is expressible here and why "nothing ran" is not —
     * see {@see Measurement::couldNotRun()}, which is a different report.
     *
     * @param string           $name
     * @param non-empty-string $detail
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function failed(string $name, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($name, false, $detail);
    }
}
