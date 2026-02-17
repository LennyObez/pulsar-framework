<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function trim;

/**
 * Admin ticket detail controller: full ticket view with conversation thread.
 */
#[Internal(reason: 'Ticket admin controller; implementation detail')]
final readonly class TicketDetailController
{
    use RendersAdminView;

    public function __construct(
        private TicketRepositoryInterface $ticketRepository,
        private TicketMessageRepositoryInterface $messageRepository,
        private TicketServiceInterface $ticketService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/tickets/{id}: Full ticket view with messages.
     */
    public function show(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.view');

        /** @var string $id */
        $id = $request->getAttribute('id', '');
        $ticket = $this->ticketRepository->findById($id);

        if ($ticket === null) {
            return Response::json(['error' => 'Ticket not found.'], 404);
        }

        $messages = $this->messageRepository->findByTicket($ticket->id, includeInternal: true);

        $data = [
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticketNumber,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->label(),
                'status_badge' => $ticket->status->badgeVariant(),
                'priority' => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'priority_badge' => $ticket->priority->badgeVariant(),
                'category_id' => $ticket->categoryId,
                'assignee_id' => $ticket->assigneeId,
                'reporter_id' => $ticket->reporterId,
                'reporter_email' => $ticket->reporterEmail,
                'reporter_name' => $ticket->reporterName,
                'tags' => $ticket->tags,
                'created_at' => $ticket->createdAt->format('c'),
                'updated_at' => $ticket->updatedAt->format('c'),
                'resolved_at' => $ticket->resolvedAt?->format('c'),
                'closed_at' => $ticket->closedAt?->format('c'),
            ],
            'messages' => array_map(static fn(TicketMessage $m) => [
                'id' => $m->id,
                'author_id' => $m->authorId,
                'author_name' => $m->authorName,
                'body' => $m->body,
                'is_internal' => $m->isInternal,
                'attachments' => $m->attachments,
                'created_at' => $m->createdAt->format('c'),
            ], $messages),
        ];

        return $this->respondWithView($request, 'admin.tickets.detail', $data);
    }

    /**
     * POST /admin/tickets/{id}/assign: Assign ticket to an agent.
     */
    public function assign(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.assign');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $assigneeId = trim((string) ($body['assignee_id'] ?? ''));

        if ($assigneeId === '') {
            return Response::json(['error' => 'Assignee ID is required.'], 422);
        }

        $ticket = $this->ticketService->assign($id, $assigneeId);

        return Response::json([
            'ticket_number' => $ticket->ticketNumber,
            'assignee_id' => $ticket->assigneeId,
            'status' => $ticket->status->value,
        ]);
    }

    /**
     * PUT /admin/tickets/{id}/status: Change ticket status.
     */
    public function changeStatus(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.change_status');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $statusValue = trim((string) ($body['status'] ?? ''));
        $newStatus = TicketStatus::tryFrom($statusValue);

        if ($newStatus === null) {
            return Response::json(['error' => 'Invalid status value.'], 422);
        }

        $ticket = $this->ticketService->changeStatus($id, $newStatus);

        return Response::json([
            'ticket_number' => $ticket->ticketNumber,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
        ]);
    }

    /**
     * PUT /admin/tickets/{id}/priority: Change ticket priority.
     */
    public function changePriority(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.change_priority');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $priorityValue = trim((string) ($body['priority'] ?? ''));
        $newPriority = TicketPriority::tryFrom($priorityValue);

        if ($newPriority === null) {
            return Response::json(['error' => 'Invalid priority value.'], 422);
        }

        $ticket = $this->ticketService->changePriority($id, $newPriority);

        return Response::json([
            'ticket_number' => $ticket->ticketNumber,
            'priority' => $ticket->priority->value,
            'priority_label' => $ticket->priority->label(),
        ]);
    }

    /**
     * POST /admin/tickets/{id}/messages: Add a public reply.
     */
    public function addMessage(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.reply');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $messageBody = trim((string) ($body['body'] ?? ''));

        if ($messageBody === '') {
            return Response::json(['error' => 'Message body is required.'], 422);
        }

        $message = $this->ticketService->addMessage(
            ticketId: $id,
            authorId: $identity->id(),
            authorName: $identity->displayName() ?? 'Agent',
            body: $messageBody,
        );

        return Response::json([
            'id' => $message->id,
            'created_at' => $message->createdAt->format('c'),
        ], 201);
    }

    /**
     * POST /admin/tickets/{id}/notes: Add an internal note.
     */
    public function addNote(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.add_note');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $noteBody = trim((string) ($body['body'] ?? ''));

        if ($noteBody === '') {
            return Response::json(['error' => 'Note body is required.'], 422);
        }

        $message = $this->ticketService->addInternalNote(
            ticketId: $id,
            authorId: $identity->id(),
            authorName: $identity->displayName() ?? 'Agent',
            body: $noteBody,
        );

        return Response::json([
            'id' => $message->id,
            'is_internal' => true,
            'created_at' => $message->createdAt->format('c'),
        ], 201);
    }

    /**
     * POST /admin/tickets/{id}/escalate: Escalate the ticket.
     */
    public function escalate(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.escalate');

        /** @var string $id */
        $id = $request->getAttribute('id', '');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $newAssigneeId = isset($body['assignee_id']) && is_string($body['assignee_id']) && $body['assignee_id'] !== ''
            ? $body['assignee_id']
            : null;

        $ticket = $this->ticketService->escalate($id, $newAssigneeId);

        return Response::json([
            'ticket_number' => $ticket->ticketNumber,
            'priority' => $ticket->priority->value,
            'priority_label' => $ticket->priority->label(),
            'assignee_id' => $ticket->assigneeId,
        ]);
    }
}
