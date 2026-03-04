<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketDashboardController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

final class TicketDashboardControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsJsonWithStats(): void
    {
        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $ticketRepo->method('countByStatus')->willReturn([
            'open' => 5,
            'in_progress' => 3,
            'waiting_on_customer' => 1,
            'resolved' => 10,
            'closed' => 20,
        ]);
        $ticketRepo->method('countResolvedToday')->willReturn(2);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $controller = new TicketDashboardController($ticketRepo, $gate);

        $request = new ServerRequest('GET', '/admin/tickets');
        $request = $request->withAttribute('identity', $identity)
            ->withHeader('Accept', 'application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame(5, $data['stats']['open']);
        self::assertSame(4, $data['stats']['in_progress']);
        self::assertSame(10, $data['stats']['resolved']);
        self::assertSame(2, $data['stats']['resolved_today']);
    }

    #[Test]
    public function indexThrowsWhenNotAuthenticated(): void
    {
        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $gate = $this->createStub(GateInterface::class);

        $controller = new TicketDashboardController($ticketRepo, $gate);

        $request = new ServerRequest('GET', '/admin/tickets');

        $this->expectException(\Pulsar\Auth\Exception\AuthenticationException::class);
        $controller->index($request);
    }

    #[Test]
    public function indexThrowsWhenUnauthorized(): void
    {
        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $controller = new TicketDashboardController($ticketRepo, $gate);

        $request = new ServerRequest('GET', '/admin/tickets');
        $request = $request->withAttribute('identity', $identity);

        $this->expectException(\Pulsar\Auth\Exception\AuthorizationException::class);
        $controller->index($request);
    }
}
