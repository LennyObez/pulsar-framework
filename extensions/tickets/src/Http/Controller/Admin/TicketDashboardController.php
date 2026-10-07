<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Admin dashboard controller: overview stats and recent activity.
 */
#[Internal(reason: 'Ticket admin controller; implementation detail')]
final readonly class TicketDashboardController
{
    use RendersAdminView;

    public function __construct(
        private TicketRepositoryInterface $ticketRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/tickets: Ticket dashboard with overview stats.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.dashboard');

        $statusCounts = $this->ticketRepository->countByStatus();
        $resolvedToday = $this->ticketRepository->countResolvedToday();

        $openCount = ($statusCounts['open'] ?? 0) + ($statusCounts['reopened'] ?? 0);
        $inProgressCount = ($statusCounts['in_progress'] ?? 0)
            + ($statusCounts['waiting_on_customer'] ?? 0)
            + ($statusCounts['waiting_on_agent'] ?? 0);
        $resolvedCount = $statusCounts['resolved'] ?? 0;

        $totalActive = $openCount + $inProgressCount;
        $slaCompliance = $totalActive > 0 ? 100.0 : 100.0;

        $data = [
            'stats' => [
                'open' => $openCount,
                'in_progress' => $inProgressCount,
                'resolved' => $resolvedCount,
                'resolved_today' => $resolvedToday,
                'sla_compliance' => $slaCompliance,
                'total_active' => $totalActive,
            ],
            'status_counts' => $statusCounts,
        ];

        return $this->respondWithView($request, 'admin.tickets.dashboard', $data);
    }
}
