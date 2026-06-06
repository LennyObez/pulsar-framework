<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Payment;
use Pulsar\Extension\Payments\Domain\PaymentMethod;
use Pulsar\Extension\Payments\Domain\PaymentStatus;

/**
 * Database-backed payment repository.
 */
#[Internal]
final readonly class DbPaymentRepository
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ConnectionInterface $connection,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function save(Payment $payment): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO payments (id, amount, currency, status, method, gateway, customer_id,
                    subscription_id, invoice_id, idempotency_key, failure_reason, created_at)
                VALUES (:id, :amount, :currency, :status, :method, :gateway, :customer_id,
                    :subscription_id, :invoice_id, :idempotency_key, :failure_reason, :created_at)
                ON CONFLICT (id) DO UPDATE SET
                    status = :status, failure_reason = :failure_reason
                SQL,
            [
                'id' => $payment->id,
                'amount' => $payment->amount->amount,
                'currency' => $payment->amount->currency->value,
                'status' => $payment->status->value,
                'method' => $payment->method->value,
                'gateway' => $payment->gateway,
                'customer_id' => $payment->customerId,
                'subscription_id' => $payment->subscriptionId,
                'invoice_id' => $payment->invoiceId,
                'idempotency_key' => $payment->idempotencyKey,
                'failure_reason' => $payment->failureReason,
                'created_at' => $payment->createdAt->format('c'),
            ],
        );
    }

    public function findById(string $id): ?Payment
    {
        $result = $this->connection->query(
            'SELECT * FROM payments WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<Payment>
     */
    public function findByCustomer(string $customerId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM payments WHERE customer_id = :customer_id ORDER BY created_at DESC',
            ['customer_id' => $customerId],
        );

        return $result->map(self::hydrate(...));
    }

    private static function hydrate(Row $row): Payment
    {
        return new Payment(
            id: $row->getString('id'),
            amount: Money::of($row->getInt('amount'), Currency::from($row->getString('currency'))),
            status: PaymentStatus::from($row->getString('status')),
            method: PaymentMethod::from($row->getString('method')),
            gateway: $row->getString('gateway'),
            customerId: $row->getString('customer_id'),
            subscriptionId: $row->getNullableString('subscription_id'),
            invoiceId: $row->getNullableString('invoice_id'),
            idempotencyKey: $row->getString('idempotency_key'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            failureReason: $row->getNullableString('failure_reason'),
        );
    }
}
