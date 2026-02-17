<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketListController;

#[CoversClass(TicketListController::class)]
final class TicketListControllerTest extends TestCase
{
    private TicketRepositoryInterface&Stub $ticketRepo;
    private GateInterface&Stub $gate;
    private TicketListController $controller;

    protected function setUp(): void
    {
        $this->ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $this->gate = $this->createStub(GateInterface::class);
        $this->gate->method('denies')->willReturn(false);

        $this->controller = new TicketListController(
            $this->ticketRepo,
            $this->gate,
        );
    }

    #[Test]
    public function indexReturnsJsonWithTicketsAndPagination(): void
    {
        $ticket = Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Help',
            description: 'Need help',
            reporterEmail: 'user@test.com',
            reporterName: 'User',
        );

        $result = new PaginationResult(
            items: [$ticket],
            total: 1,
            hasMore: false,
            perPage: 25,
            currentPage: 1,
            lastPage: 1,
        );

        $this->ticketRepo->method('findAll')->willReturn($result);

        $request = $this->authenticatedRequest([]);
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['tickets']);
        self::assertSame('TKT-2026-000001', $body['tickets'][0]['ticket_number']);
        self::assertArrayHasKey('pagination', $body);
        self::assertArrayHasKey('filters', $body);
    }

    #[Test]
    public function indexPassesStatusFilterToRepository(): void
    {
        $emptyResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
            currentPage: 1,
            lastPage: 1,
        );
        $this->ticketRepo->method('findAll')->willReturn($emptyResult);

        $request = $this->authenticatedRequest(['status' => 'open']);
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('open', $body['filters']['status']);
    }

    #[Test]
    public function indexPassesPriorityFilterToRepository(): void
    {
        $emptyResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
            currentPage: 1,
            lastPage: 1,
        );
        $this->ticketRepo->method('findAll')->willReturn($emptyResult);

        $request = $this->authenticatedRequest(['priority' => 'critical']);
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('critical', $body['filters']['priority']);
    }

    #[Test]
    public function indexPassesCategoryAndAssigneeFilters(): void
    {
        $emptyResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
            currentPage: 1,
            lastPage: 1,
        );
        $this->ticketRepo->method('findAll')->willReturn($emptyResult);

        $request = $this->authenticatedRequest([
            'category_id' => 'cat-99',
            'assignee_id' => 'agent-7',
        ]);
        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cat-99', $body['filters']['category_id']);
        self::assertSame('agent-7', $body['filters']['assignee_id']);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function authenticatedRequest(array $queryParams): ServerRequestInterface&Stub
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
        ]);
        $request->method('getQueryParams')->willReturn($queryParams);

        return $request;
    }
}
