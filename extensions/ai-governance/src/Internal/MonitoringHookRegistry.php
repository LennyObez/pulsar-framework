<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;

/**
 * The set of registered monitoring hooks, shared by the two collaborators that
 * need it.
 *
 * The lifecycle manager runs the hooks; the high-risk obligations gate has to
 * know whether any exist, because EU AI Act Article 72 makes post-market
 * monitoring a precondition of placing a high-risk system on the market rather
 * than an optional extra. Holding the collection here — instead of privately
 * inside the lifecycle manager — is what lets the gate answer that question
 * without either class reaching into the other.
 */
#[Internal(reason: 'Shared hook collection; register hooks via AiLifecycleManagerInterface::addMonitoringHook()')]
final class MonitoringHookRegistry implements MonitoringHookRegistryInterface
{
    /** @var list<MonitoringHookInterface> */
    private array $hooks = [];

    #[Override]
    public function add(MonitoringHookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    /**
     * @return list<MonitoringHookInterface>
     */
    #[NoDiscard]
    #[Override]
    public function all(): array
    {
        return $this->hooks;
    }

    #[NoDiscard]
    #[Override]
    public function isEmpty(): bool
    {
        return $this->hooks === [];
    }
}
