<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Internal\Service\TicketAutoAssigner;

#[CoversClass(TicketAutoAssigner::class)]
final class TicketAutoAssignerTest extends TestCase
{
    private TicketRepositoryInterface&Stub $ticketRepo;

    protected function setUp(): void
    {
        $this->ticketRepo = $this->createStub(TicketRepositoryInterface::class);
    }

    #[Test]
    public function selectAgentReturnsNullWhenAutoAssignDisabled(): void
    {
        $config = new TicketsConfig(autoAssignEnabled: false);
        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, ['agent-1']);

        self::assertNull($assigner->selectAgent());
    }

    #[Test]
    public function selectAgentReturnsNullWhenNoAgentsAvailable(): void
    {
        $config = new TicketsConfig(autoAssignEnabled: true);
        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, []);

        self::assertNull($assigner->selectAgent());
    }

    #[Test]
    public function selectAgentUsesRoundRobinByDefault(): void
    {
        $config = new TicketsConfig(
            autoAssignEnabled: true,
            autoAssignStrategy: 'round_robin',
        );

        // agent-1 has 5 tickets, agent-2 has 2
        $this->ticketRepo->method('findByAssignee')->willReturnCallback(
            function (string $agentId): PaginationResult {
                $total = $agentId === 'agent-1' ? 5 : 2;
                return new PaginationResult(items: [], total: $total, hasMore: false, perPage: 1, currentPage: 1, lastPage: 1);
            },
        );

        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, ['agent-1', 'agent-2']);

        self::assertSame('agent-2', $assigner->selectAgent());
    }

    #[Test]
    public function selectAgentUsesLoadBalancedStrategy(): void
    {
        $config = new TicketsConfig(
            autoAssignEnabled: true,
            autoAssignStrategy: 'load_balanced',
        );

        // agent-1: 3 tickets, agent-2: 1, agent-3: 7
        $this->ticketRepo->method('findByAssignee')->willReturnCallback(
            function (string $agentId): PaginationResult {
                $totals = ['agent-1' => 3, 'agent-2' => 1, 'agent-3' => 7];
                $total = $totals[$agentId] ?? 0;
                return new PaginationResult(items: [], total: $total, hasMore: false, perPage: 1, currentPage: 1, lastPage: 1);
            },
        );

        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, ['agent-1', 'agent-2', 'agent-3']);

        self::assertSame('agent-2', $assigner->selectAgent());
    }

    #[Test]
    public function roundRobinFallsBackToFirstAgentWhenAllEqualLoad(): void
    {
        $config = new TicketsConfig(
            autoAssignEnabled: true,
            autoAssignStrategy: 'round_robin',
        );

        $this->ticketRepo->method('findByAssignee')->willReturn(
            new PaginationResult(items: [], total: 3, hasMore: false, perPage: 1, currentPage: 1, lastPage: 1),
        );

        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, ['agent-a', 'agent-b']);

        // All equal load; first one with that load wins (agent-a, found first in iteration)
        self::assertSame('agent-a', $assigner->selectAgent());
    }

    #[Test]
    public function selectAgentReturnsSingleAgentWhenOnlyOneAvailable(): void
    {
        $config = new TicketsConfig(autoAssignEnabled: true);

        $this->ticketRepo->method('findByAssignee')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 1, currentPage: 1, lastPage: 1),
        );

        $assigner = new TicketAutoAssigner($config, $this->ticketRepo, ['sole-agent']);

        self::assertSame('sole-agent', $assigner->selectAgent());
    }
}
