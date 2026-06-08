<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketSla;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketEscalated;

use function array_filter;
use function array_values;
use function round;

/**
 * Scheduled job that checks active tickets for SLA violations
 * and triggers escalation rules when thresholds are exceeded.
 */
#[Internal(reason: 'SLA monitoring; implementation detail')]
final readonly class TicketSlaMonitor
{
    public function __construct(
        private TicketsConfig $config,
        private TicketRepositoryInterface $ticketRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Check all active tickets for SLA violations and dispatch escalation events.
     *
     * @return int Number of escalated tickets
     */
    public function checkViolations(): int
    {
        $escalatedCount = 0;
        $now = new DateTimeImmutable();

        $activeStatuses = [
            TicketStatus::Open,
            TicketStatus::InProgress,
            TicketStatus::WaitingOnAgent,
            TicketStatus::Reopened,
        ];

        foreach ($activeStatuses as $status) {
            $page = 1;

            do {
                $result = $this->ticketRepository->findByStatus($status, $page, 100);

                foreach ($result->items as $ticket) {
                    $escalated = $this->checkTicketSla($ticket, $now);

                    if ($escalated) {
                        $escalatedCount++;
                    }
                }

                $page++;
            } while ($result->hasMore);
        }

        return $escalatedCount;
    }

    /**
     * Check a single ticket against its SLA rules.
     */
    private function checkTicketSla(Ticket $ticket, DateTimeImmutable $now): bool
    {
        $sla = $this->findSlaForPriority($ticket);

        if ($sla === null) {
            return false;
        }

        $elapsedMinutes = (int) round(($now->getTimestamp() - $ticket->createdAt->getTimestamp()) / 60);
        $triggered = false;

        foreach ($sla->escalationRules as $rule) {
            if ($elapsedMinutes >= $rule->thresholdMinutes) {
                $this->eventDispatcher->dispatch(new TicketEscalated(
                    ticket: $ticket,
                    triggeredRule: $rule,
                    elapsedMinutes: $elapsedMinutes,
                ));
                $triggered = true;
            }
        }

        return $triggered;
    }

    private function findSlaForPriority(Ticket $ticket): ?TicketSla
    {
        $matching = array_values(array_filter(
            $this->config->slaRules,
            static fn(TicketSla $sla): bool => $sla->priority === $ticket->priority,
        ));

        return $matching[0] ?? null;
    }
}
