<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentMark;

/**
 * The Article 50 transparency duties a deployment has declared and discharges.
 *
 * This is the one AI Act obligation a framework can carry a real share of, and
 * it is the one in force. Article 50 has applied since 2 August 2026; the digital
 * omnibus that deferred the high-risk chapter to 2027 and 2028 left it untouched.
 * Everything else the Act asks of a provider — the risk management system, the
 * technical documentation, conformity assessment, registration — is discharged in
 * documents no framework can inspect, and AiActMapping records those as operator
 * responsibilities rather than pretending to observe them.
 *
 * The division of labour here is deliberate. This interface holds WHAT WAS
 * DECLARED and produces marks on demand; it does not render a notice and does not
 * attach a mark to a response, because a framework that silently injected either
 * would be deciding on a deployment's behalf whether a legal duty had been met.
 * The declaration is observable, which is what makes the compliance control
 * honest: it reports that a surface owes a disclosure and that one exists, and
 * names the operator artefact showing where the person actually sees it.
 *
 * No generation time is taken from a clock. Whatever produced the output knows
 * when it did so; a marker guessing at it would be recording the time of
 * marking and calling it the time of generation.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface AiTransparencyInterface
{
    /**
     * Declare a surface's Article 50 position.
     *
     * The policy has already refused to exist if it was incoherent, so this
     * records rather than validates.
     */
    public function declare(AiTransparencyPolicy $policy): void;

    /**
     * @param non-empty-string $surfaceId
     */
    #[NoDiscard]
    public function policyFor(string $surfaceId): ?AiTransparencyPolicy;

    /**
     * The notice this surface must show, if Article 50(1) demands one of it.
     *
     * Returns null both when no policy was declared and when the surface owes no
     * disclosure. Those are different situations and the caller that needs to
     * tell them apart should ask policyFor() instead — this method answers the
     * rendering question, not the compliance question.
     *
     * @param non-empty-string $surfaceId
     */
    #[NoDiscard]
    public function disclosureFor(string $surfaceId): ?AiInteractionDisclosure;

    /**
     * Produce the Article 50(2) mark for one generated output.
     *
     * Refuses for a surface that was never declared: a mark that cannot be traced
     * to a declared policy is an assertion about nothing, and the traceability is
     * the part that makes it evidence.
     *
     * @param non-empty-string $surfaceId
     * @param non-empty-string $modelId
     * @param int $generatedAt Unix timestamp at which the output was generated
     */
    #[NoDiscard]
    public function mark(
        string $surfaceId,
        SyntheticContentKind $kind,
        string $modelId,
        int $generatedAt,
    ): SyntheticContentMark;

    /**
     * Every surface that has declared a position, for the compliance report.
     *
     * @return list<AiTransparencyPolicy>
     */
    #[NoDiscard]
    public function declared(): array;
}
