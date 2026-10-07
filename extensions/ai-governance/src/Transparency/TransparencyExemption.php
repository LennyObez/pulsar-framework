<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Transparency;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * The exemptions Article 50 grants, and the paragraph each one can discharge.
 *
 * The Act really does grant these, so a layer that could not express them would
 * be wrong about the law. The hazard is the opposite one: an exemption that a
 * deployment can assert silently turns the whole obligation into an opt-out, and
 * a duty nothing can be observed to fail is not a duty.
 *
 * Two properties keep that from happening.
 *
 * An exemption is DECLARED, never inferred. Nothing in this extension decides
 * that a disclosure is unnecessary; a deployment states which exemption it
 * claims, and the claim is carried into the compliance report as an operator
 * assertion to be justified rather than as an absence nobody notices.
 *
 * An exemption discharges only the paragraph that grants it. Article 50(1) is
 * excused by obviousness; Article 50(2) is not — a video is not marked as
 * generated merely because a viewer might guess. Article 50(2) is excused by
 * assistive editing; Article 50(1) is not. Asking whether an exemption covers a
 * paragraph it was not written for returns false here rather than passing.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum TransparencyExemption: string
{
    /** No exemption claimed: the duty applies and must be discharged. */
    case None = 'none';

    /**
     * Article 50(1): interaction with an AI system is "obvious from the point of
     * view of a natural person who is reasonably well-informed, observant and
     * circumspect". It excuses no marking duty.
     */
    case ObviousFromContext = 'obvious_from_context';

    /**
     * Articles 50(1) and 50(2): systems authorised by law to detect, prevent,
     * investigate or prosecute criminal offences.
     *
     * Article 50(1) carves the exemption BACK for systems "available for the
     * public to report a criminal offence", which is why claiming it also
     * requires stating that the surface is not one. See
     * AiTransparencyPolicy::publicCrimeReporting.
     */
    case LawEnforcementAuthorised = 'law_enforcement_authorised';

    /**
     * Article 50(2): the system "performs an assistive function for standard
     * editing" or does "not substantially alter the input data provided by the
     * deployer or the semantics thereof". It excuses no disclosure duty: a
     * spell-checker that talks to you is still talking to you.
     */
    case AssistiveEditingOnly = 'assistive_editing_only';

    /**
     * Whether this exemption can discharge the Article 50(1) disclosure duty.
     */
    #[NoDiscard]
    public function dischargesDisclosure(): bool
    {
        return match ($this) {
            self::ObviousFromContext, self::LawEnforcementAuthorised => true,
            self::None, self::AssistiveEditingOnly => false,
        };
    }

    /**
     * Whether this exemption can discharge the Article 50(2) marking duty.
     */
    #[NoDiscard]
    public function dischargesMarking(): bool
    {
        return match ($this) {
            self::AssistiveEditingOnly, self::LawEnforcementAuthorised => true,
            self::None, self::ObviousFromContext => false,
        };
    }

    /**
     * The wording an assessor is to be shown against a claimed exemption.
     *
     * A claim with no stated basis is the same as no claim, so every case that
     * excuses something names what must be produced to justify it.
     */
    #[NoDiscard]
    public function justificationArtefact(): string
    {
        return match ($this) {
            self::None => 'None: no exemption is claimed and the duty is discharged in full.',
            self::ObviousFromContext => 'The assessment recording why interaction with an AI system is '
                . 'obvious on this surface to a reasonably well-informed, observant and circumspect '
                . 'person, in the circumstances and context of use (Article 50(1)).',
            self::LawEnforcementAuthorised => 'The legal basis authorising this system to detect, '
                . 'prevent, investigate or prosecute criminal offences, the safeguards for the rights '
                . 'and freedoms of third parties, and the record that the surface is not available to '
                . 'the public for reporting a criminal offence (Article 50(1), 50(2)).',
            self::AssistiveEditingOnly => 'The description of the assistive editing function, showing '
                . 'that it does not substantially alter the deployer\'s input data or its semantics '
                . '(Article 50(2)).',
        };
    }
}
