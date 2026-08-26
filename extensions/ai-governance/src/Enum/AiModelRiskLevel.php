<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Risk classification for AI models, in the tiers of the EU AI Act
 * (Regulation (EU) 2024/1689).
 *
 * The four cases correspond one-to-one with the Act's tiers, and the two that
 * carry pre-market duties decide something in this extension rather than
 * describing an intention:
 *
 * - `Unacceptable` — the practices Article 5 prohibits outright. No such model
 *   holds production status: the model registry refuses to register or
 *   transition one into production, and the prohibited-practice deployment gate
 *   refuses the deployment before the registry is reached. There is no artefact
 *   that unblocks it, because the Act bans the practice rather than
 *   conditioning it.
 * - `High` — Article 6 systems: the Annex I safety components and the Annex III
 *   use cases. Before such a system is placed on the market its provider must
 *   operate a risk management system (Article 9), hold technical documentation
 *   (Article 11 and Annex IV) and have a post-market monitoring plan
 *   (Article 72(3), which Annex IV point 9 requires that documentation to
 *   contain). The high-risk obligations gate requires the artefact this
 *   extension models for each: an impact assessment on record, an attached
 *   model card, and at least one registered monitoring hook.
 * - `Limited` — the Article 50 transparency duties: telling a person they are
 *   interacting with an AI system, marking synthetic content. These have applied
 *   since 2 August 2026, and unlike the tier above them they were NOT deferred by
 *   the digital omnibus, which makes this the tier whose obligations bind today.
 *   They are discharged in what a running deployment shows a person and puts in a
 *   response, so no pre-deployment gate can enforce them the way the high-risk
 *   gate enforces artefacts. What this extension provides instead is
 *   {@see \Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface}:
 *   a surface declares whether it interacts with natural persons, what it
 *   generates and which exemption if any it relies on, and the declaration is
 *   refused if Article 50 does not permit it. Rendering the notice and attaching
 *   the mark stay the deployment's own work, deliberately — a framework that did
 *   either silently would be deciding on its behalf that a legal duty had been met.
 * - `Minimal` — no obligations under the Act beyond the voluntary codes of
 *   conduct of Article 95. Carries no gate, deliberately.
 *
 * ISO 42001:2023 Clause 6.1.2 requires an AI risk assessment; this
 * classification is its output and the input to every gate above.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiModelRiskLevel: string
{
    case Minimal = 'minimal';
    case Limited = 'limited';
    case High = 'high';
    case Unacceptable = 'unacceptable';

    /**
     * Whether the Act prohibits the practice outright.
     *
     * Article 5 bans these systems rather than permitting them subject to
     * conditions, so no impact assessment, documentation or monitoring makes a
     * model in this tier deployable.
     */
    #[NoDiscard]
    public function isProhibited(): bool
    {
        return match ($this) {
            self::Unacceptable => true,
            self::High, self::Limited, self::Minimal => false,
        };
    }

    /**
     * Whether the tier carries the obligations a provider must discharge before
     * the system is placed on the market: the risk management system of
     * Article 9 and the technical documentation of Article 11 and Annex IV,
     * both in Chapter III Section 2, together with the post-market monitoring
     * plan of Article 72(3), which sits in Chapter IX but is pre-market all the
     * same because Annex IV point 9 requires the technical documentation to
     * contain it.
     *
     * False for `Unacceptable`: a prohibited practice is not a high-risk system
     * with an unmet checklist, and reporting it as one would suggest the
     * checklist could be completed.
     */
    #[NoDiscard]
    public function carriesHighRiskObligations(): bool
    {
        return match ($this) {
            self::High => true,
            self::Unacceptable, self::Limited, self::Minimal => false,
        };
    }
}
