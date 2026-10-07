<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether this deployment can actually discharge an Article 50 transparency duty.
 *
 * IT USED TO ASK SOMETHING WEAKER, and that is the whole history of this file.
 * The first version observed that the ai-governance extension was registered and
 * that a transparency contract had resolved. Both are resolved identities, and
 * resolution stopped proving behaviour when
 * {@see \Pulsar\Compliance\Control\ObservationGrade::provesBehaviour()} was
 * narrowed to Measured — so this control could not be Satisfied by any deployment
 * this release can build, and the EU AI Act mapping satisfied nothing at all. The
 * report was honest and it was inert, which is a control stuck in one direction
 * and the same class of broken instrument as one stuck in the other.
 *
 * It was stuck for a second reason nobody had noticed: the composition root
 * resolved eight AI governance contracts and not `AiTransparencyInterface`, so
 * `ai_transparency_resolved` read "nothing answered" on a container that had bound
 * it. One of the two required facts could not be present on ANY deployment. That
 * is fixed in {@see \Pulsar\Core\Wiring\ComplianceCatalogWiring}.
 *
 * The deciding fact is now {@see ObservationId::AiTransparencyExercised}: a
 * surface declared through the live subsystem, the policy read back with both
 * Article 50 duties intact, a synthetic-content mark minted and its
 * machine-readable form checked against what was asked for, and a mark REFUSED for
 * a surface nobody declared. See {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}
 * for what it runs and for the one declaration it leaves behind.
 *
 * READ WHAT THIS STILL DOES NOT SAY. It does not say a person was shown a notice,
 * and it does not say generated output left the process carrying a mark. Neither
 * is observable from a container: the notice is discharged where it is rendered,
 * the mark where the response is written, and a probe that graded either from what
 * it can see here would be reporting a capability as a compliance outcome — which
 * is how ISO 27001 A.5.1 once came to be graded from the existence of a CSP
 * header. Article 50(1) and 50(2) themselves are discharged against operator
 * artefacts, which `ai-act-art-50-1` and `ai-act-art-50-2` name and keep.
 *
 * WHY ALL THREE FACTS ARE STILL REQUIRED. Only the first can carry the control —
 * {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()} admits a fact as proof
 * only at grade Measured and only on the estate the control regulates — and the
 * other two are necessary conditions that cannot. That asymmetry is the ADR-0062
 * residue stated where it applies: a required slot is filled by presence
 * regardless of grade, so a Resolved fact can block a Satisfied verdict and can
 * never produce one. Here that is the behaviour wanted, because a deployment whose
 * transparency subsystem answers a drill while its extension is unregistered is a
 * deployment whose report should say so.
 *
 * This one carries more weight than the ISO 42001 capability probes beside it,
 * because the duty behind it is in force. Article 50 has applied since 2 August
 * 2026 and the digital omnibus that deferred the high-risk chapter to 2 December
 * 2027 and 2 August 2028 did not move it. A deployment that answers this probe
 * with "extension absent" is not early for a future obligation; it has no way to
 * discharge one it already owes.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiTransparencyProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_transparency';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a surface declared through the live transparency subsystem reads back '
            . 'owing both Article 50 duties, and whether generated output can be given a '
            . 'machine-readable mark that traces to that declaration.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::AiTransparencyExercised,
            ObservationId::AiGovernanceExtensionActive,
            ObservationId::AiTransparencyResolved,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Read the evidence line for ai_transparency_exercised: it names which subject '
                . 'failed — the declaration not reading back, the mark omitting what it was '
                . 'minted for, or a mark being issued for a surface nobody declared — and what '
                . 'the subsystem returned instead.',
            'Install and enable the pulsar/ai-governance extension, then declare an '
                . 'AiTransparencyPolicy for every surface that talks to people or emits '
                . 'generated content.',
            'If this deployment operates no AI system that interacts with natural persons '
                . 'and generates no synthetic content, remove AiAct from enabled_frameworks '
                . 'rather than leaving Article 50 claimed and unobserved.',
        ];
    }
}
