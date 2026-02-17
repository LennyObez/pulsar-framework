<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin ticket list controller: filterable ticket listing.
 */
#[Internal(reason: 'Ticket admin controller; implementation detail')]
final readonly class TicketListController
{
    use RendersAdminView;

    public function __construct(
        private TicketRepositoryInterface $ticketRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/tickets/list: Filterable ticket list.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.list');

        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 25);

        $statusFilter = isset($params['status']) && is_string($params['status'])
            ? TicketStatus::tryFrom($params['status'])
            : null;
        $priorityFilter = isset($params['priority']) && is_string($params['priority'])
            ? TicketPriority::tryFrom($params['priority'])
            : null;
        $categoryFilter = isset($params['category_id']) && is_string($params['category_id']) && $params['category_id'] !== ''
            ? $params['category_id']
            : null;
        $assigneeFilter = isset($params['assignee_id']) && is_string($params['assignee_id']) && $params['assignee_id'] !== ''
            ? $params['assignee_id']
            : null;

        $result = $this->ticketRepository->findAll(
            page: $page,
            perPage: $perPage,
            status: $statusFilter,
            priority: $priorityFilter,
            categoryId: $categoryFilter,
            assigneeId: $assigneeFilter,
        );

        $data = [
            'tickets' => array_map(static fn(Ticket $t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticketNumber,
                'subject' => $t->subject,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'status_badge' => $t->status->badgeVariant(),
                'priority' => $t->priority->value,
                'priority_label' => $t->priority->label(),
                'priority_badge' => $t->priority->badgeVariant(),
                'assignee_id' => $t->assigneeId,
                'reporter_name' => $t->reporterName,
                'reporter_email' => $t->reporterEmail,
                'created_at' => $t->createdAt->format('c'),
                'updated_at' => $t->updatedAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'filters' => [
                'status' => $statusFilter?->value,
                'priority' => $priorityFilter?->value,
                'category_id' => $categoryFilter,
                'assignee_id' => $assigneeFilter,
            ],
        ];

        return $this->respondWithView($request, 'admin.tickets.list', $data);
    }
}
