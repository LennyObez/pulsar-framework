<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;

/**
 * The hook collection, as the two collaborators that share it see it.
 *
 * {@see MonitoringHookRegistry} is the implementation this extension wires, and
 * it is `final` — so before this contract existed, both
 * {@see AiLifecycleManager} and {@see Gate\HighRiskObligationsGate} were welded
 * to that one class with no way to wrap it. Naming the contract instead is what
 * lets the extension's own composition root put something else in its place: a
 * registry that refuses hooks after boot, one that counts registrations for the
 * Article 72 record, or a stub in a test that needs the gate's answer without
 * building a hook.
 *
 * Deliberately not in `Contracts\`. That namespace is this extension's public
 * surface, and the door an integrator registers a hook through is
 * {@see \Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface::addMonitoringHook()}.
 * Publishing a second door onto the same collection would make the registry an
 * integration point it was never meant to be.
 */
#[Internal(reason: 'Shared hook collection; register hooks via AiLifecycleManagerInterface::addMonitoringHook()')]
interface MonitoringHookRegistryInterface
{
    /**
     * Add a hook to the collection.
     */
    public function add(MonitoringHookInterface $hook): void;

    /**
     * Every registered hook, in registration order.
     *
     * @return list<MonitoringHookInterface>
     */
    #[NoDiscard]
    public function all(): array;

    /**
     * Whether nothing has been registered.
     *
     * Asked by the high-risk obligations gate, because EU AI Act Article 72(3)
     * makes post-market monitoring a precondition of placing a high-risk system
     * on the market rather than an optional extra.
     */
    #[NoDiscard]
    public function isEmpty(): bool;
}
