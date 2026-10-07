<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\TicketSla;

use function array_map;

/**
 * Configuration DTO for the Tickets extension.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketsConfig
{
    /**
     * @param bool $autoAssignEnabled Whether auto-assignment is enabled
     * @param string $autoAssignStrategy Assignment strategy: 'round_robin' or 'load_balanced'
     * @param bool $emailNotificationsEnabled Whether to send email notifications
     * @param bool $contactFormIntegration Whether CMS contact form creates tickets
     * @param list<TicketSla> $slaRules SLA rules per priority
     * @param int $autoCloseAfterDays Days after resolution to auto-close (0 = disabled)
     */
    public function __construct(
        public bool $autoAssignEnabled = false,
        public string $autoAssignStrategy = 'round_robin',
        public bool $emailNotificationsEnabled = true,
        public bool $contactFormIntegration = false,
        public array $slaRules = [],
        public int $autoCloseAfterDays = 7,
    ) {}

    /**
     * @param array{
     *     auto_assign_enabled?: bool|int|string,
     *     auto_assign_strategy?: string,
     *     email_notifications_enabled?: bool|int|string,
     *     contact_form_integration?: bool|int|string,
     *     sla_rules?: list<array{priority: string, first_response_minutes: int, resolution_minutes: int, escalation_rules?: list<array{threshold_minutes: int, action: string, target?: string}>}>,
     *     auto_close_after_days?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $slaRules = array_map(TicketSla::fromArray(...), $data['sla_rules'] ?? []);

        return new self(
            autoAssignEnabled: (bool) ($data['auto_assign_enabled'] ?? false),
            autoAssignStrategy: $data['auto_assign_strategy'] ?? 'round_robin',
            emailNotificationsEnabled: (bool) ($data['email_notifications_enabled'] ?? true),
            contactFormIntegration: (bool) ($data['contact_form_integration'] ?? false),
            slaRules: $slaRules,
            autoCloseAfterDays: $data['auto_close_after_days'] ?? 7,
        );
    }
}
