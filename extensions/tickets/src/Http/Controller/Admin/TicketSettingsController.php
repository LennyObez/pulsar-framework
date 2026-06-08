<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Domain\TicketSla;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;

/**
 * Admin settings controller: SLA config, auto-assignment rules, notifications.
 */
#[Internal(reason: 'Ticket admin controller; implementation detail')]
final readonly class TicketSettingsController
{
    use RendersAdminView;

    public function __construct(
        private TicketsConfig $config,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/tickets/settings: View current settings.
     */
    public function show(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.settings');

        $data = [
            'settings' => [
                'auto_assign_enabled' => $this->config->autoAssignEnabled,
                'auto_assign_strategy' => $this->config->autoAssignStrategy,
                'email_notifications_enabled' => $this->config->emailNotificationsEnabled,
                'contact_form_integration' => $this->config->contactFormIntegration,
                'auto_close_after_days' => $this->config->autoCloseAfterDays,
                'sla_rules' => array_map(static fn(TicketSla $sla) => [
                    'priority' => $sla->priority->value,
                    'first_response_minutes' => $sla->firstResponseMinutes,
                    'resolution_minutes' => $sla->resolutionMinutes,
                    'escalation_rules' => array_map(static fn($rule) => [
                        'threshold_minutes' => $rule->thresholdMinutes,
                        'action' => $rule->action,
                        'target' => $rule->target,
                    ], $sla->escalationRules),
                ], $this->config->slaRules),
            ],
        ];

        return $this->respondWithView($request, 'admin.tickets.settings', $data);
    }
}
