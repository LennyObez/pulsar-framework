<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether this deployment can express its Article 50 position at all.
 *
 * READ WHAT THIS DOES NOT SAY. It does not say a person was shown a notice, and
 * it does not say generated output left the process carrying a mark. Neither is
 * observable from a container: the notice is discharged where it is rendered, the
 * mark where the response is written, and a probe that graded either from a
 * binding would be reporting a capability as a compliance outcome — which is how
 * ISO 27001 A.5.1 once came to be graded from the existence of a CSP header.
 *
 * What it says is narrower and true: the ai-governance extension is active and a
 * transparency contract resolved, so the deployment has somewhere to declare its
 * surfaces and something to mint marks from. Article 50 itself is discharged
 * against operator artefacts, which ai-act-art-50-1 and ai-act-art-50-2 name.
 *
 * This one carries more weight than the ISO 42001 capability probes beside it,
 * because the duty behind it is in force. Article 50 has applied since 2 August
 * 2026 and the digital omnibus that deferred the high-risk chapter to 2 December
 * 2027 and 2 August 2028 did not move it. A deployment that answers this probe
 * with "extension absent" is not early for a future obligation; it has no way to
 * express a duty it already owes.
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
        return 'Whether the AI governance extension is active and a transparency contract resolved, '
            . 'so Article 50 positions can be declared and generated output marked.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
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
            'Install and enable the pulsar/ai-governance extension, then declare an '
                . 'AiTransparencyPolicy for every surface that talks to people or emits '
                . 'generated content.',
            'If this deployment operates no AI system that interacts with natural persons '
                . 'and generates no synthetic content, remove AiAct from enabled_frameworks '
                . 'rather than leaving Article 50 claimed and unobserved.',
        ];
    }
}
