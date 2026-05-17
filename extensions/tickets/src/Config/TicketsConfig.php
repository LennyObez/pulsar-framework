<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\TicketSla;

use function array_key_exists;
use function array_map;
use function is_array;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $slaRules = [];
        if (array_key_exists('sla_rules', $data) && is_array($data['sla_rules'])) {
            /** @var list<array{priority: string, first_response_minutes: int, resolution_minutes: int, escalation_rules?: list<array{threshold_minutes: int, action: string, target?: string}>}> $rawRules */
            $rawRules = $data['sla_rules'];
            $slaRules = array_map(TicketSla::fromArray(...), $rawRules);
        }

        return new self(
            autoAssignEnabled: (bool) ($data['auto_assign_enabled'] ?? false),
            autoAssignStrategy: (string) ($data['auto_assign_strategy'] ?? 'round_robin'),
            emailNotificationsEnabled: (bool) ($data['email_notifications_enabled'] ?? true),
            contactFormIntegration: (bool) ($data['contact_form_integration'] ?? false),
            slaRules: $slaRules,
            autoCloseAfterDays: (int) ($data['auto_close_after_days'] ?? 7),
        );
    }
}
