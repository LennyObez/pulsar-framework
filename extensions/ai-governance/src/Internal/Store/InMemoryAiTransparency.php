<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentMark;

use function array_values;

/**
 * The default store for declared Article 50 positions.
 *
 * In memory, and that is the right default rather than a placeholder. A
 * transparency policy is a declaration about how an application is built, so it
 * is fixed at boot by whatever wires the application and does not change while a
 * request runs. Persisting it would add a store to keep in step with the code it
 * describes, and a stale row saying a surface owes no disclosure is worse than no
 * row at all. A deployment that wants its declarations in a database implements
 * the contract; nothing here is privileged.
 *
 * @internal
 */
#[Internal(reason: 'Default store behind AiTransparencyInterface; the contract is the public surface')]
final class InMemoryAiTransparency implements AiTransparencyInterface
{
    /** @var array<string, AiTransparencyPolicy> */
    private array $policies = [];

    #[Override]
    public function declare(AiTransparencyPolicy $policy): void
    {
        $this->policies[$policy->surfaceId] = $policy;
    }

    #[Override]
    #[NoDiscard]
    public function policyFor(string $surfaceId): ?AiTransparencyPolicy
    {
        return $this->policies[$surfaceId] ?? null;
    }

    #[Override]
    #[NoDiscard]
    public function disclosureFor(string $surfaceId): ?AiInteractionDisclosure
    {
        $policy = $this->policies[$surfaceId] ?? null;

        if ($policy === null || ! $policy->owesDisclosure()) {
            return null;
        }

        // A policy that owes a disclosure holds one: its constructor refused to
        // exist otherwise. The null coalesce is the type system's, not a doubt.
        return $policy->disclosure;
    }

    #[Override]
    #[NoDiscard]
    public function mark(
        string $surfaceId,
        SyntheticContentKind $kind,
        string $modelId,
        int $generatedAt,
    ): SyntheticContentMark {
        if (! isset($this->policies[$surfaceId])) {
            throw AiGovernanceException::surfaceNotDeclared($surfaceId);
        }

        // Marking is NOT conditional on owesMarkingFor(). An exemption excuses a
        // deployment from being required to mark; it never forbids marking, and a
        // caller that asks for a mark has decided this output is generated. The
        // exemption is recorded for the report, where it belongs.
        return new SyntheticContentMark($kind, $surfaceId, $modelId, $generatedAt);
    }

    /**
     * @return list<AiTransparencyPolicy>
     */
    #[Override]
    #[NoDiscard]
    public function declared(): array
    {
        return array_values($this->policies);
    }
}
