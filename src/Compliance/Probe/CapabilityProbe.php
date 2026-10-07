<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

use function array_map;

/**
 * The shape almost every probe has: some facts must all hold, others corroborate
 * the conclusion, and an optional operator scope assertion can take the control
 * out of play.
 *
 * Subclasses are named, single-purpose, and carry a stable id, so ISO 27001
 * A.8.15, PCI Req 10.2 and NIST DE.AE cite ONE measurement rather than three
 * pieces of prose that can drift apart. A parameterised probe configured per
 * mapping would have re-opened exactly that gap.
 *
 * What a subclass may say is now only which facts it needs. The decision table
 * moved to {@see ControlRequirement} and runs in {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()},
 * against the evidence set the engine gathered, so nothing here can reach an
 * outcome at all. Two things changed with it, both from review:
 *
 *  - Admissibility is judged over the REQUIRED facts only. It used to be judged
 *    over required-plus-supporting, so one admissible SUPPORTING observation —
 *    a corroborating detail that decides nothing — could carry a control to
 *    Satisfied on its own, and PCI Req 2.3 was being carried by the database
 *    transport while its actual subject sat in the supporting list.
 *  - `satisfiedSummary()` is gone. It was a hard-coded constant per subclass,
 *    asserting in prose what the evidence printed beneath it could contradict —
 *    the status literal ADR-0041 deleted from the mappings, restored as a
 *    sentence. The summary is generated from the observations that actually
 *    decided the verdict and names each of them.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
abstract readonly class CapabilityProbe implements ControlProbeInterface
{
    /**
     * The facts that must ALL hold for the control to be satisfied, and the only
     * facts that can carry it.
     *
     * @return non-empty-list<ObservationId>
     */
    #[NoDiscard]
    abstract protected function required(): array;

    /**
     * What the operator must do when a required fact is missing. Printed under
     * `fix:` in the report, one line each.
     *
     * @return non-empty-list<non-empty-string>
     */
    #[NoDiscard]
    abstract protected function remediations(): array;

    /**
     * Facts that corroborate the conclusion and are printed with it.
     *
     * They cannot decide it. A supporting fact is listed under the finding, and
     * an admissible one is named in the summary as corroboration, but the verdict
     * turns on {@see required()} alone.
     *
     * @return list<ObservationId>
     */
    #[NoDiscard]
    protected function supporting(): array
    {
        return [];
    }

    /**
     * The operator scope assertion that can put this control out of play, if any.
     *
     * Only ever a claim about the DATA the deployment handles — "we store no
     * cardholder data" — never about a framework's applicability as a whole.
     * Enabling a framework in config/compliance.php IS the operator's assertion
     * that they must satisfy it, so a framework's own controls can never be
     * scoped out by the framework being irrelevant; that would let one line of
     * config silence a whole standard.
     */
    #[NoDiscard]
    protected function scope(): ?ObservationId
    {
        return null;
    }

    #[Override]
    #[NoDiscard]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of(
            required: array_map(
                fn(ObservationId $id): RequiredFact => RequiredFact::contributing($id, $this->remediations()),
                $this->required(),
            ),
            supporting: $this->supporting(),
            scope: $this->scope(),
        );
    }
}
