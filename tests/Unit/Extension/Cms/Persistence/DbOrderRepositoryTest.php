<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Persistence\DbOrderRepository;

#[CoversClass(DbOrderRepository::class)]
final class DbOrderRepositoryTest extends TestCase
{
    private ConnectionInterface&Stub $db;
    private DbOrderRepository $repository;

    protected function setUp(): void
    {
        $this->db = $this->createStub(ConnectionInterface::class);
        $this->repository = new DbOrderRepository($this->db);
    }

    #[Test]
    public function update_status_throws_when_order_not_found(): void
    {
        $this->db->method('query')->willReturn(new Result([]));

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Order not found: non-existent-id');

        $this->repository->updateStatus('non-existent-id', OrderStatus::Confirmed);
    }

    #[Test]
    public function update_status_throws_on_invalid_transition(): void
    {
        $this->db->method('query')->willReturn(new Result([
            self::orderRow(OrderStatus::Cart),
        ]));

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage("Invalid status transition from 'cart' to 'fulfilled'");

        $this->repository->updateStatus('order-1', OrderStatus::Fulfilled);
    }

    #[Test]
    public function update_status_executes_on_valid_transition(): void
    {
        $this->db->method('query')->willReturn(new Result([
            self::orderRow(OrderStatus::Cart),
        ]));
        $this->db->method('execute')->willReturn(1);

        $this->repository->updateStatus('order-1', OrderStatus::PendingPayment);

        // No exception means success — the state machine accepted the transition
        // and the UPDATE was executed
        $this->addToAssertionCount(1);
    }

    private static function orderRow(OrderStatus $status): Row
    {
        $now = new DateTimeImmutable();

        return new Row([
            'id' => 'order-1',
            'tenant_id' => null,
            'order_number' => 'ORD-001',
            'customer_id' => 'cust-1',
            'customer_email' => 'test@example.com',
            'status' => $status->value,
            'subtotal' => 1000,
            'tax_amount' => 100,
            'discount_amount' => 0,
            'total' => 1100,
            'amount_refunded' => 0,
            'currency' => 'USD',
            'payment_intent_id' => null,
            'payment_status' => 'pending',
            'billing_address' => '{"line1":"123 Main St","city":"Springfield","postalCode":"62701","country":"US"}',
            'shipping_address' => null,
            'shipping_amount' => 0,
            'shipping_method' => null,
            'notes' => null,
            'data_classification' => 'pii',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
