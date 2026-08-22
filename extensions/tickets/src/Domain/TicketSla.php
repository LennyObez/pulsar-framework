<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use Pulsar\Api\Api;

/**
 * SLA configuration for a ticket priority level.
 *
 * Defines maximum response and resolution times (in minutes),
 * along with escalation rules when SLA thresholds are breached.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketSla
{
    /**
     * @param TicketPriority $priority The priority this SLA applies to
     * @param int $firstResponseMinutes Max minutes for first agent response
     * @param int $resolutionMinutes Max minutes for full resolution
     * @param list<EscalationRule> $escalationRules Ordered escalation rules
     */
    public function __construct(
        public TicketPriority $priority,
        public int $firstResponseMinutes,
        public int $resolutionMinutes,
        public array $escalationRules,
    ) {}

    /**
     * @param array{
     *     priority: string,
     *     first_response_minutes: int,
     *     resolution_minutes: int,
     *     escalation_rules?: list<array{threshold_minutes: int, action: string, target?: string}>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $rules = [];
        foreach ($data['escalation_rules'] ?? [] as $rule) {
            $rules[] = new EscalationRule(
                thresholdMinutes: $rule['threshold_minutes'],
                action: $rule['action'],
                target: $rule['target'] ?? null,
            );
        }

        return new self(
            priority: TicketPriority::from($data['priority']),
            firstResponseMinutes: $data['first_response_minutes'],
            resolutionMinutes: $data['resolution_minutes'],
            escalationRules: $rules,
        );
    }
}
