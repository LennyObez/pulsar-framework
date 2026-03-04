<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;
use function trim;

/**
 * Front-office ticket controller: public ticket submission and status lookup.
 */
#[Internal(reason: 'Front-office controller; implementation detail')]
final readonly class TicketController
{
    public function __construct(
        private TicketServiceInterface $ticketService,
        private TicketRepositoryInterface $ticketRepository,
        private TicketMessageRepositoryInterface $messageRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * POST /support/tickets: Create a new support ticket.
     */
    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];

        $subject = trim((string) ($body['subject'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $priorityValue = (string) ($body['priority'] ?? 'normal');
        $categoryId = isset($body['category_id']) && is_string($body['category_id']) && $body['category_id'] !== ''
            ? $body['category_id']
            : null;

        if ($subject === '' || $description === '' || $email === '' || $name === '') {
            return Response::json([
                'error' => 'Subject, description, email, and name are required.',
            ], 422);
        }

        $priority = TicketPriority::tryFrom($priorityValue) ?? TicketPriority::Normal;

        $ticket = $this->ticketService->create(
            subject: $subject,
            description: $description,
            reporterEmail: $email,
            reporterName: $name,
            priority: $priority,
            categoryId: $categoryId,
        );

        return Response::json([
            'ticket_number' => $ticket->ticketNumber,
            'status' => $ticket->status->value,
            'message' => 'Your ticket has been submitted successfully.',
        ], 201);
    }

    /**
     * GET /support/tickets/{number}: View ticket status.
     */
    public function show(ServerRequestInterface $request): Response
    {
        /** @var string $number */
        $number = $request->getAttribute('number', '');

        $ticket = $this->ticketRepository->findByNumber($number);

        if ($ticket === null) {
            return Response::json(['error' => 'Ticket not found.'], 404);
        }

        $messages = $this->messageRepository->findByTicket($ticket->id, includeInternal: false);

        $data = [
            'ticket' => [
                'ticket_number' => $ticket->ticketNumber,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->label(),
                'priority' => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'reporter_name' => $ticket->reporterName,
                'created_at' => $ticket->createdAt->format('c'),
                'updated_at' => $ticket->updatedAt->format('c'),
                'resolved_at' => $ticket->resolvedAt?->format('c'),
            ],
            'messages' => array_map(static fn($m) => [
                'author_name' => $m->authorName,
                'body' => $m->body,
                'created_at' => $m->createdAt->format('c'),
            ], $messages),
        ];

        if ($this->templateEngine !== null) {
            $html = $this->templateEngine->render('tickets.show', $data);

            return Response::html($html);
        }

        return Response::json($data);
    }

    /**
     * POST /support/tickets/{number}/messages: Add a reply to a ticket.
     */
    public function addMessage(ServerRequestInterface $request): Response
    {
        /** @var string $number */
        $number = $request->getAttribute('number', '');

        $ticket = $this->ticketRepository->findByNumber($number);

        if ($ticket === null) {
            return Response::json(['error' => 'Ticket not found.'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];

        $messageBody = trim((string) ($body['body'] ?? ''));
        $authorName = trim((string) ($body['name'] ?? $ticket->reporterName));

        if ($messageBody === '') {
            return Response::json(['error' => 'Message body is required.'], 422);
        }

        $message = $this->ticketService->addMessage(
            ticketId: $ticket->id,
            authorId: null,
            authorName: $authorName,
            body: $messageBody,
        );

        return Response::json([
            'message' => 'Your reply has been added.',
            'created_at' => $message->createdAt->format('c'),
        ], 201);
    }
}
