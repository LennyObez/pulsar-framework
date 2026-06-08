<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceStatus;
use Pulsar\Extension\Payments\Domain\Money;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Database-backed invoice repository.
 */
#[Internal]
final readonly class DbInvoiceRepository
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(Invoice $invoice): void
    {
        $lineItemsJson = json_encode(
            array_map(
                static fn($item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price_amount' => $item->unitPrice->amount,
                    'unit_price_currency' => $item->unitPrice->currency->value,
                    'total_amount' => $item->total->amount,
                    'total_currency' => $item->total->currency->value,
                ],
                $invoice->lineItems,
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->connection->execute(
            <<<'SQL'
                INSERT INTO invoices (
                    id, invoice_number, customer_id, subscription_id,
                    subtotal_amount, subtotal_currency, tax_amount, tax_currency,
                    total_amount, total_currency, status, line_items_json,
                    paid_at, due_date, created_at
                ) VALUES (
                    :id, :invoice_number, :customer_id, :subscription_id,
                    :subtotal_amount, :subtotal_currency, :tax_amount, :tax_currency,
                    :total_amount, :total_currency, :status, :line_items_json,
                    :paid_at, :due_date, :created_at
                ) ON CONFLICT (id) DO UPDATE SET
                    status = :status, paid_at = :paid_at
                SQL,
            [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoiceNumber,
                'customer_id' => $invoice->customerId,
                'subscription_id' => $invoice->subscriptionId,
                'subtotal_amount' => $invoice->subtotal->amount,
                'subtotal_currency' => $invoice->subtotal->currency->value,
                'tax_amount' => $invoice->tax->amount,
                'tax_currency' => $invoice->tax->currency->value,
                'total_amount' => $invoice->total->amount,
                'total_currency' => $invoice->total->currency->value,
                'status' => $invoice->status->value,
                'line_items_json' => $lineItemsJson,
                'paid_at' => $invoice->paidAt?->format('c'),
                'due_date' => $invoice->dueDate?->format('c'),
                'created_at' => $invoice->createdAt->format('c'),
            ],
        );
    }

    public function findById(string $id): ?Invoice
    {
        $result = $this->connection->query(
            'SELECT * FROM invoices WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByInvoiceNumber(string $invoiceNumber): ?Invoice
    {
        $result = $this->connection->query(
            'SELECT * FROM invoices WHERE invoice_number = :num LIMIT 1',
            ['num' => $invoiceNumber],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<Invoice>
     */
    public function findByCustomer(string $customerId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM invoices WHERE customer_id = :cid ORDER BY created_at DESC',
            ['cid' => $customerId],
        );

        return $result->map(self::hydrate(...));
    }

    private static function hydrate(Row $row): Invoice
    {
        /** @var string $lineItemsJson */
        $lineItemsJson = $row->getString('line_items_json');

        /** @var list<array{description: string, quantity: int, unit_price_amount: int, unit_price_currency: string, total_amount: int, total_currency: string}> $lineItemsRaw */
        $lineItemsRaw = json_decode($lineItemsJson, true, 16, JSON_THROW_ON_ERROR);

        $lineItems = array_map(
            static fn(array $item) => new \Pulsar\Extension\Payments\Domain\InvoiceLineItem(
                description: $item['description'],
                quantity: $item['quantity'],
                unitPrice: Money::of($item['unit_price_amount'], Currency::from($item['unit_price_currency'])),
                total: Money::of($item['total_amount'], Currency::from($item['total_currency'])),
            ),
            $lineItemsRaw,
        );

        return new Invoice(
            id: $row->getString('id'),
            invoiceNumber: $row->getString('invoice_number'),
            customerId: $row->getString('customer_id'),
            subscriptionId: $row->getNullableString('subscription_id'),
            subtotal: Money::of($row->getInt('subtotal_amount'), Currency::from($row->getString('subtotal_currency'))),
            tax: Money::of($row->getInt('tax_amount'), Currency::from($row->getString('tax_currency'))),
            total: Money::of($row->getInt('total_amount'), Currency::from($row->getString('total_currency'))),
            status: InvoiceStatus::from($row->getString('status')),
            lineItems: $lineItems,
            paidAt: self::toDateTime($row->getNullableString('paid_at')),
            dueDate: self::toDateTime($row->getNullableString('due_date')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
