<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\InvoiceRendererInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function count;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Invoice generation and retrieval service.
 */
#[Internal(reason: 'Use InvoiceServiceInterface for public API')]
final readonly class InvoiceService implements InvoiceServiceInterface
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private OrderItemRepositoryInterface $orderItems,
        private InvoiceRepositoryInterface $invoices,
        private InvoiceRendererInterface $renderer,
    ) {}

    public function generate(string $orderId): Invoice
    {
        // Idempotent: return existing invoice if already generated
        $existing = $this->invoices->findByOrder($orderId);

        if ($existing !== null) {
            return $existing;
        }

        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        $invoiceNumber = $this->invoices->nextInvoiceNumber($order->tenantId);
        $now = new DateTimeImmutable();
        $dueAt = $now->modify('+30 days');

        // Compute evidence hash for tamper detection
        $items = $this->orderItems->findByOrder($orderId);
        $evidencePayload = json_encode([
            'orderId' => $orderId,
            'orderNumber' => $order->orderNumber,
            'invoiceNumber' => $invoiceNumber,
            'subtotal' => $order->subtotal,
            'taxAmount' => $order->taxAmount,
            'discountAmount' => $order->discountAmount,
            'total' => $order->total,
            'currency' => $order->currency,
            'issuedAt' => $now->format('c'),
            'itemCount' => count($items),
        ], JSON_THROW_ON_ERROR);

        $evidenceHash = hash('sha256', $evidencePayload);

        $id = UuidGenerator::v7();

        $invoice = new Invoice(
            id: $id,
            orderId: $orderId,
            invoiceNumber: $invoiceNumber,
            issuedAt: $now,
            dueAt: $dueAt,
            pdfStoragePath: null,
            pdfHash: null,
            evidenceHash: $evidenceHash,
            dataClassification: DataClassification::Pii,
        );

        $this->invoices->save($invoice);

        return $invoice;
    }

    public function getInvoiceHtml(string $invoiceId): string
    {
        $invoice = $this->invoices->findById($invoiceId);

        if ($invoice === null) {
            throw CmsException::contentNotFound($invoiceId);
        }

        $order = $this->orders->findById($invoice->orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($invoice->orderId);
        }

        $items = $this->orderItems->findByOrder($invoice->orderId);

        return $this->renderer->render($invoice, $order, $items);
    }

}
