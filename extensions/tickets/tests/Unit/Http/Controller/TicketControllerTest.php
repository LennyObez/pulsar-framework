<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Http\Controller\TicketController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

final class TicketControllerTest extends TestCase
{
    private TicketServiceInterface&Stub $ticketService;
    private TicketRepositoryInterface&Stub $ticketRepository;
    private TicketMessageRepositoryInterface&Stub $messageRepository;

    protected function setUp(): void
    {
        $this->ticketService = $this->createStub(TicketServiceInterface::class);
        $this->ticketRepository = $this->createStub(TicketRepositoryInterface::class);
        $this->messageRepository = $this->createStub(TicketMessageRepositoryInterface::class);
    }

    #[Test]
    public function createReturns201WithTicketNumber(): void
    {
        $ticket = Ticket::create(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'a@b.com',
            reporterName: 'User',
        );

        $this->ticketService->method('create')->willReturn($ticket);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('POST', '/support/tickets');
        $request = $request->withParsedBody([
            'subject' => 'Test',
            'description' => 'Desc',
            'email' => 'a@b.com',
            'name' => 'User',
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('TKT-2026-000001', $data['ticket_number']);
    }

    #[Test]
    public function createReturns422WhenFieldsMissing(): void
    {
        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('POST', '/support/tickets');
        $request = $request->withParsedBody([
            'subject' => '',
            'description' => '',
            'email' => '',
            'name' => '',
        ]);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function showReturns404WhenTicketNotFound(): void
    {
        $this->ticketRepository->method('findByNumber')->willReturn(null);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('GET', '/support/tickets/TKT-2026-000001');
        $request = $request->withAttribute('number', 'TKT-2026-000001');

        $response = $controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsTicketDataAsJson(): void
    {
        $ticket = Ticket::create(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'a@b.com',
            reporterName: 'User',
        );

        $this->ticketRepository->method('findByNumber')->willReturn($ticket);
        $this->messageRepository->method('findByTicket')->willReturn([]);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('GET', '/support/tickets/TKT-2026-000001');
        $request = $request->withAttribute('number', 'TKT-2026-000001');

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('TKT-2026-000001', $data['ticket']['ticket_number']);
    }

    #[Test]
    public function addMessageReturns404WhenTicketNotFound(): void
    {
        $this->ticketRepository->method('findByNumber')->willReturn(null);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('POST', '/support/tickets/TKT-0000-000001/messages');
        $request = $request->withAttribute('number', 'TKT-0000-000001');
        $request = $request->withParsedBody(['body' => 'Hello']);

        $response = $controller->addMessage($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function addMessageReturns422WhenBodyEmpty(): void
    {
        $ticket = Ticket::create('t1', 'TKT-2026-000001', 'T', 'D', 'a@b.com', 'U');
        $this->ticketRepository->method('findByNumber')->willReturn($ticket);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('POST', '/support/tickets/TKT-2026-000001/messages');
        $request = $request->withAttribute('number', 'TKT-2026-000001');
        $request = $request->withParsedBody(['body' => '']);

        $response = $controller->addMessage($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function addMessageReturns201OnSuccess(): void
    {
        $ticket = Ticket::create('t1', 'TKT-2026-000001', 'T', 'D', 'a@b.com', 'User');
        $this->ticketRepository->method('findByNumber')->willReturn($ticket);

        $message = TicketMessage::create('m1', 't1', null, 'User', 'Reply text');
        $this->ticketService->method('addMessage')->willReturn($message);

        $controller = new TicketController(
            $this->ticketService,
            $this->ticketRepository,
            $this->messageRepository,
        );

        $request = new ServerRequest('POST', '/support/tickets/TKT-2026-000001/messages');
        $request = $request->withAttribute('number', 'TKT-2026-000001');
        $request = $request->withParsedBody(['body' => 'Reply text']);

        $response = $controller->addMessage($request);

        self::assertSame(201, $response->getStatusCode());
    }
}
