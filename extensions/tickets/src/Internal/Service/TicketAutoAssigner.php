<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;

/**
 * Auto-assigns tickets to agents using round-robin or load-balanced strategies.
 */
#[Internal(reason: 'Auto-assignment logic; implementation detail')]
final readonly class TicketAutoAssigner
{
    /**
     * @param list<string> $agentIds Available agent user IDs
     */
    public function __construct(
        private TicketsConfig $config,
        private TicketRepositoryInterface $ticketRepository,
        private array $agentIds,
    ) {}

    /**
     * Select the next agent to assign a ticket to.
     *
     * Returns null if no agents are available or auto-assignment is disabled.
     */
    public function selectAgent(): ?string
    {
        if (!$this->config->autoAssignEnabled || $this->agentIds === []) {
            return null;
        }

        return match ($this->config->autoAssignStrategy) {
            'load_balanced' => $this->selectByLoad(),
            default => $this->selectRoundRobin(),
        };
    }

    /**
     * Round-robin: select the agent with the fewest total assigned tickets.
     * Falls back to the first agent if all have equal load.
     */
    private function selectRoundRobin(): string
    {
        $minCount = PHP_INT_MAX;
        $selectedAgent = $this->agentIds[0];

        foreach ($this->agentIds as $agentId) {
            $result = $this->ticketRepository->findByAssignee($agentId, 1, 1);
            $count = $result->total ?? 0;

            if ($count < $minCount) {
                $minCount = $count;
                $selectedAgent = $agentId;
            }
        }

        return $selectedAgent;
    }

    /**
     * Load-balanced: select the agent with the fewest active (non-resolved/closed) tickets.
     */
    private function selectByLoad(): string
    {
        $loads = [];

        foreach ($this->agentIds as $agentId) {
            $result = $this->ticketRepository->findByAssignee($agentId, 1, 1);
            $loads[$agentId] = $result->total ?? 0;
        }

        $minLoad = PHP_INT_MAX;
        $selectedAgent = $this->agentIds[0];

        foreach ($loads as $agentId => $load) {
            if ($load < $minLoad) {
                $minLoad = $load;
                $selectedAgent = $agentId;
            }
        }

        return $selectedAgent;
    }
}
