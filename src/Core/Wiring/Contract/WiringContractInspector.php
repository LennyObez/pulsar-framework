<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Contract;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;

/**
 * Inspects wiring contracts against the live container.
 *
 * Given the contracts a boot produced and the resulting container, it reports
 * (a) required bindings that no wiring satisfied — an intra-framework gap — and
 * (b) features degraded because an optional binding is unbound. This is the
 * single check that would have caught the TaggedCacheInterface regression the
 * instant it shipped.
 */
#[Internal]
final readonly class WiringContractInspector
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * @param iterable<WiringContract> $contracts
     * @return list<DegradedFeature>   Features disabled by a missing optional binding
     */
    public function degradedFeatures(iterable $contracts): array
    {
        $degraded = [];

        foreach ($contracts as $contract) {
            foreach ($contract->optional as $optional) {
                if (!$this->container->has($optional->binding)) {
                    $degraded[] = new DegradedFeature(
                        component: $contract->component,
                        feature: $optional->feature,
                        missingBinding: $optional->binding,
                        fix: $optional->fix,
                        security: $optional->security,
                    );
                }
            }
        }

        return $degraded;
    }

    /**
     * Required bindings that no wiring in the graph provides — a hard gap that
     * should fail CI (a component declared a dependency nothing satisfies).
     *
     * @param iterable<WiringContract> $contracts
     * @return list<array{component: string, binding: string}>
     */
    public function unsatisfiedRequirements(iterable $contracts): array
    {
        $contracts = [...$contracts];

        $provided = [];

        foreach ($contracts as $contract) {
            foreach ($contract->provides as $binding) {
                $provided[$binding] = true;
            }
        }

        $unsatisfied = [];

        foreach ($contracts as $contract) {
            foreach ($contract->requires as $binding) {
                // Satisfied if another wiring declares it provided OR it is
                // already resolvable in the container (bound outside the graph,
                // e.g. by the composition root before wiring runs).
                if (!isset($provided[$binding]) && !$this->container->has($binding)) {
                    $unsatisfied[] = ['component' => $contract->component, 'binding' => $binding];
                }
            }
        }

        return $unsatisfied;
    }
}
