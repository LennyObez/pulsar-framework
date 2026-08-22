<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketDetailController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

final class TicketDetailControllerTest extends TestCase
{
    private TicketRepositoryInterface&Stub $ticketRepo;
    private TicketMessageRepositoryInterface&Stub $messageRepo;
    private TicketServiceInterface&Stub $ticketService;
    private GateInterface&Stub $gate;
    private IdentityInterface&Stub $identity;

    protected function setUp(): void
    {
        $this->ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $this->messageRepo = $this->createStub(TicketMessageRepositoryInterface::class);
        $this->ticketService = $this->createStub(TicketServiceInterface::class);
        $this->gate = $this->createStub(GateInterface::class);
        $this->gate->method('denies')->willReturn(false);
        $this->identity = $this->createStub(IdentityInterface::class);
        $this->identity->method('isAuthenticated')->willReturn(true);
        $this->identity->method('id')->willReturn('agent-1');
        $this->identity->method('displayName')->willReturn('Agent Smith');
    }

    #[Test]
    public function showReturnsTicketWithMessages(): void
    {
        $ticket = $this->createTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);
        $this->messageRepo->method('findByTicket')->willReturn([]);

        $controller = $this->createController();
        $request = $this->makeRequest('GET', '/admin/tickets/t1', ['id' => 't1']);

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('TKT-2026-000001', $data['ticket']['ticket_number']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $this->ticketRepo->method('findById')->willReturn(null);

        $controller = $this->createController();
        $request = $this->makeRequest('GET', '/admin/tickets/missing', ['id' => 'missing']);

        $response = $controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function assignReturns422WhenAssigneeEmpty(): void
    {
        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/assign', ['id' => 't1']);
        $request = $request->withParsedBody(['assignee_id' => '']);

        $response = $controller->assign($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function assignReturnsTicketOnSuccess(): void
    {
        $ticket = $this->createTicket()->assign('agent-2');
        $this->ticketService->method('assign')->willReturn($ticket);

        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/assign', ['id' => 't1']);
        $request = $request->withParsedBody(['assignee_id' => 'agent-2']);

        $response = $controller->assign($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('agent-2', $data['assignee_id']);
    }

    #[Test]
    public function changeStatusReturns422ForInvalidStatus(): void
    {
        $controller = $this->createController();
        $request = $this->makeRequest('PUT', '/admin/tickets/t1/status', ['id' => 't1']);
        $request = $request->withParsedBody(['status' => 'invalid_status']);

        $response = $controller->changeStatus($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function addMessageReturns422WhenBodyEmpty(): void
    {
        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/messages', ['id' => 't1']);
        $request = $request->withParsedBody(['body' => '']);

        $response = $controller->addMessage($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function addMessageReturns201OnSuccess(): void
    {
        $message = TicketMessage::create('m1', 't1', 'agent-1', 'Agent Smith', 'Reply');
        $this->ticketService->method('addMessage')->willReturn($message);

        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/messages', ['id' => 't1']);
        $request = $request->withParsedBody(['body' => 'Reply']);

        $response = $controller->addMessage($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function addNoteReturns201WithInternalFlag(): void
    {
        $note = TicketMessage::createInternal('n1', 't1', 'agent-1', 'Agent Smith', 'Internal');
        $this->ticketService->method('addInternalNote')->willReturn($note);

        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/notes', ['id' => 't1']);
        $request = $request->withParsedBody(['body' => 'Internal']);

        $response = $controller->addNote($request);

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_internal']);
    }

    #[Test]
    public function escalateReturnsUpdatedTicket(): void
    {
        $ticket = $this->createTicket()->changePriority(TicketPriority::High);
        $this->ticketService->method('escalate')->willReturn($ticket);

        $controller = $this->createController();
        $request = $this->makeRequest('POST', '/admin/tickets/t1/escalate', ['id' => 't1']);
        $request = $request->withParsedBody([]);

        $response = $controller->escalate($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('high', $data['priority']);
    }

    private function createController(): TicketDetailController
    {
        return new TicketDetailController(
            $this->ticketRepo,
            $this->messageRepo,
            $this->ticketService,
            $this->gate,
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function makeRequest(string $method, string $uri, array $attributes = []): ServerRequest
    {
        $request = new ServerRequest($method, $uri);
        $request = $request->withAttribute('identity', $this->identity)
            ->withHeader('Accept', 'application/json');

        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request;
    }

    private function createTicket(): Ticket
    {
        return Ticket::create(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'test@example.com',
            reporterName: 'Test User',
        );
    }
}
