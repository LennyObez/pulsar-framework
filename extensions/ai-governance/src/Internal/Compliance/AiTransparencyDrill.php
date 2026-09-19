<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Compliance;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;

use function array_map;

/**
 * Lets the compliance assessor exercise this extension's Article 50 subsystem.
 *
 * The framework cannot call {@see AiTransparencyInterface} itself. That contract
 * belongs to this package, which is optional — trust tier `verified`, kind
 * `product` — and absent from the root autoload by ADR-0004, so a file under
 * `src/` naming it would make the framework depend on one of its own extensions.
 * {@see AiTransparencyDrillInterface} is declared in the framework for exactly
 * that reason and answered here, the same way the OpenTelemetry extension answers
 * `SpanProcessorInterface`.
 *
 * IT MARSHALS AND IT DOES NOT JUDGE, and the division is the point rather than a
 * style choice. Every method below hands back raw material — the policy as this
 * subsystem stored it, the mark as it would travel — and not one of them returns
 * a verdict, a status, or a boolean meaning "that worked". The comparisons that
 * decide the compliance fact are made in
 * {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}, inside the directory
 * {@see \Pulsar\Compliance\Control\MeasuringComponent} seals, and an extension
 * cannot produce an {@see \Pulsar\Compliance\Control\Observation} at all. So the
 * worst this class can do to a report is lie about what the subsystem returned,
 * which is a forgery of the material an assessor would ask to see — not a claim
 * the compliance vocabulary would carry for it.
 *
 * NOTHING HERE IS A TEST DOUBLE. It reaches the same bound
 * {@see AiTransparencyInterface} an application's own surfaces reach, through the
 * container, so a store that drops what it is given fails the assessment on this
 * path exactly as it would fail a request. A second, obliging implementation kept
 * for the compliance report is the artefact this whole subsystem exists to make
 * impossible.
 *
 * @internal
 */
#[Internal(reason: 'Compliance seam over AiTransparencyInterface; the contract is the public surface')]
final readonly class AiTransparencyDrill implements AiTransparencyDrillInterface
{
    public function __construct(
        private AiTransparencyInterface $transparency,
    ) {}

    /**
     * Declare a surface owing BOTH Article 50 duties.
     *
     * `interactsWithNaturalPersons` and a generated kind together, with no
     * exemption, is the maximal coherent declaration: the policy type refuses to
     * exist unless a surface in that position carries a notice, so what is
     * recorded here is a position that has already been checked for coherence and
     * that owes everything Article 50 can ask of a surface. A weaker declaration
     * would exercise a path where the duties are discharged rather than owed, and
     * establish less.
     *
     * `SyntheticContentKind::from()` throws for a kind outside Article 50(2)'s
     * own list rather than substituting one, which is what the seam requires: a
     * mark reporting a kind nobody asked for is worse evidence than no mark.
     *
     * @param non-empty-string $surfaceId
     * @param non-empty-string $notice
     * @param non-empty-string $locale
     * @param non-empty-string $kind
     */
    #[Override]
    public function declareSurface(string $surfaceId, string $notice, string $locale, string $kind): void
    {
        $this->transparency->declare(new AiTransparencyPolicy(
            surfaceId: $surfaceId,
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure($notice, $locale),
            generates: [SyntheticContentKind::from($kind)],
        ));
    }

    /**
     * @param non-empty-string $surfaceId
     *
     * @return array{
     *     surface_id: string,
     *     owes_disclosure: bool,
     *     owes_marking: bool,
     *     notice: string|null,
     *     locale: string|null
     * }|null
     */
    #[Override]
    #[NoDiscard]
    public function policyFor(string $surfaceId): ?array
    {
        $policy = $this->transparency->policyFor($surfaceId);

        if (!$policy instanceof AiTransparencyPolicy) {
            return null;
        }

        // Read from the POLICY rather than from disclosureFor(): that method
        // answers the rendering question and returns null both for a surface with
        // no policy and for one that owes no notice, which are the two states the
        // assessor has to be able to tell apart.
        $disclosure = $policy->disclosure;

        return [
            'surface_id' => $policy->surfaceId,
            'owes_disclosure' => $policy->owesDisclosure(),
            'owes_marking' => $policy->owesMarking(),
            'notice' => $disclosure?->notice,
            'locale' => $disclosure?->locale,
        ];
    }

    /**
     * @param non-empty-string $surfaceId
     * @param non-empty-string $kind
     * @param non-empty-string $modelId
     *
     * @return array{header: string, machine_readable: array<string, scalar>}
     */
    #[Override]
    #[NoDiscard]
    public function mark(string $surfaceId, string $kind, string $modelId, int $generatedAt): array
    {
        $mark = $this->transparency->mark(
            $surfaceId,
            SyntheticContentKind::from($kind),
            $modelId,
            $generatedAt,
        );

        // Both forms, because Article 50(2) asks for marking that is machine-
        // readable AND detectable: the field is where a proxy or a crawler meets
        // the assertion, the data is for transports that carry structure, and a
        // deployment able to produce only one of them discharges only part of it.
        return [
            'header' => $mark->toHeaderValue(),
            'machine_readable' => $mark->toArray(),
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    #[NoDiscard]
    public function declaredSurfaces(): array
    {
        return array_map(
            static fn(AiTransparencyPolicy $policy): string => $policy->surfaceId,
            $this->transparency->declared(),
        );
    }
}
