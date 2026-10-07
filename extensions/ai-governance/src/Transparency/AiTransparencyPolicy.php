<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Transparency;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function in_array;
use function trim;

/**
 * What one surface declares about its Article 50 transparency duties.
 *
 * A "surface" is whatever a deployment treats as one point of contact with a
 * person: a route, a channel, a widget. The Act attaches its duties to the system
 * as encountered, so the declaration is per-surface rather than per-application.
 *
 * The constructor refuses an incoherent declaration instead of recording one.
 * That matters more here than it looks: Article 50 has applied since 2 August
 * 2026, and the digital omnibus that deferred Chapter III to 2027 and 2028 left
 * this Article exactly where it was. A policy object that accepted "this surface
 * talks to people, claims no exemption, and carries no notice" would be modelling
 * a state the law does not allow, and every report built on it would be wrong
 * while looking complete.
 *
 * Three refusals, each for a duty that is in force:
 *
 * - A surface that interacts with natural persons and claims no exemption
 *   discharging Article 50(1) must carry a notice.
 * - A surface that generates synthetic content and claims no exemption
 *   discharging Article 50(2) must mark it.
 * - A surface claiming the law-enforcement exemption while being available to
 *   the public to report a criminal offence is refused, because Article 50(1)
 *   carves that case back out of the exemption in terms.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiTransparencyPolicy
{
    /**
     * @param non-empty-string $surfaceId
     * @param list<SyntheticContentKind> $generates output kinds this surface produces
     * @param bool $publicCrimeReporting whether the surface is available to the public
     *                                   to report a criminal offence, which Article 50(1)
     *                                   excludes from the law-enforcement exemption
     */
    public function __construct(
        public string $surfaceId,
        public bool $interactsWithNaturalPersons,
        public ?AiInteractionDisclosure $disclosure = null,
        public array $generates = [],
        public TransparencyExemption $exemption = TransparencyExemption::None,
        public bool $publicCrimeReporting = false,
    ) {
        // Identity first: every refusal below names the surface, and a message
        // naming an empty one tells the reader nothing about which declaration
        // to go and fix.
        if (trim($this->surfaceId) === '') {
            throw AiGovernanceException::surfaceIdRequired();
        }

        if (
            $this->exemption === TransparencyExemption::LawEnforcementAuthorised
            && $this->publicCrimeReporting
        ) {
            throw AiGovernanceException::exemptionCarvedBack($this->surfaceId);
        }

        if (
            $this->interactsWithNaturalPersons
            && ! $this->exemption->dischargesDisclosure()
            && ! $this->disclosure instanceof AiInteractionDisclosure
        ) {
            throw AiGovernanceException::disclosureRequired($this->surfaceId);
        }
    }

    /**
     * Whether Article 50(1) still demands a notice from this surface.
     */
    #[NoDiscard]
    public function owesDisclosure(): bool
    {
        return $this->interactsWithNaturalPersons && ! $this->exemption->dischargesDisclosure();
    }

    /**
     * Whether Article 50(2) still demands machine-readable marking from this surface.
     */
    #[NoDiscard]
    public function owesMarking(): bool
    {
        return $this->generates !== [] && ! $this->exemption->dischargesMarking();
    }

    /**
     * Whether an output of this kind must carry a mark when this surface emits it.
     */
    #[NoDiscard]
    public function owesMarkingFor(SyntheticContentKind $kind): bool
    {
        return $this->owesMarking() && in_array($kind, $this->generates, true);
    }

    /**
     * Whether Article 50(4) deep-fake disclosure can attach to anything here.
     *
     * Answering true does not mean the surface produces deep fakes. It means the
     * deployer's own Article 50(4) duty is live for this surface and cannot be
     * discharged by the provider-side marking alone.
     */
    #[NoDiscard]
    public function mayRequireDeepFakeDisclosure(): bool
    {
        foreach ($this->generates as $kind) {
            if ($kind->canConstituteDeepFake()) {
                return true;
            }
        }

        return false;
    }
}
