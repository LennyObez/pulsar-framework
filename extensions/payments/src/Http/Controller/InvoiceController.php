<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\InvoiceRendererInterface;
use Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Http\Message\Response;

use function is_string;
use function str_contains;

/**
 * Invoice controller for viewing and downloading invoices.
 *
 * Returns JSON for API requests and rendered HTML for browser requests.
 */
#[Internal]
final readonly class InvoiceController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private DbInvoiceRepository $invoiceRepository,
        private InvoiceRendererInterface $renderer,
    ) {}

    /**
     * GET /payments/invoices
     *
     * List invoices for the authenticated customer.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $customerId = $this->resolveCustomerId($request);

        if ($customerId === null) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $invoices = $this->invoiceRepository->findByCustomer($customerId);

        $items = array_map(static fn($invoice) => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoiceNumber,
            'total' => $invoice->total->amount,
            'currency' => $invoice->total->currency->value,
            'status' => $invoice->status->value,
            'due_date' => $invoice->dueDate?->format('c'),
            'paid_at' => $invoice->paidAt?->format('c'),
            'created_at' => $invoice->createdAt->format('c'),
        ], $invoices);

        return Response::json(['invoices' => $items]);
    }

    /**
     * GET /payments/invoices/{id}
     *
     * View a single invoice. Returns rendered HTML for browser visitors
     * and JSON for API clients.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawInvoiceId */
        $rawInvoiceId = $request->getAttribute('id');
        $invoiceId = is_string($rawInvoiceId) ? $rawInvoiceId : '';

        if ($invoiceId === '') {
            return Response::json(['error' => 'Missing invoice ID'], 400);
        }

        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            return Response::json(['error' => 'Invoice not found'], 404);
        }

        // Content negotiation: render HTML for browser requests
        $accept = $request->getHeaderLine('Accept');

        if ($accept !== 'application/json' && !str_contains($accept, 'application/json')) {
            $content = $this->renderer->render($invoice);

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => $this->renderer->contentType(),
                ],
                body: $content,
            );
        }

        return Response::json([
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoiceNumber,
            'customer_id' => $invoice->customerId,
            'subtotal' => $invoice->subtotal->amount,
            'tax' => $invoice->tax->amount,
            'total' => $invoice->total->amount,
            'currency' => $invoice->total->currency->value,
            'status' => $invoice->status->value,
            'line_items' => array_map(static fn($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice->amount,
                'total' => $item->total->amount,
            ], $invoice->lineItems),
            'due_date' => $invoice->dueDate?->format('c'),
            'paid_at' => $invoice->paidAt?->format('c'),
            'created_at' => $invoice->createdAt->format('c'),
        ]);
    }

    /**
     * GET /payments/invoices/{id}/download
     *
     * Download an invoice as a rendered document.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function download(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawInvoiceId */
        $rawInvoiceId = $request->getAttribute('id');
        $invoiceId = is_string($rawInvoiceId) ? $rawInvoiceId : '';

        if ($invoiceId === '') {
            return Response::json(['error' => 'Missing invoice ID'], 400);
        }

        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            return Response::json(['error' => 'Invoice not found'], 404);
        }

        $content = $this->renderer->render($invoice);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => $this->renderer->contentType(),
                'Content-Disposition' => "attachment; filename=\"{$invoice->invoiceNumber}.html\"",
            ],
            body: $content,
        );
    }

    private function resolveCustomerId(ServerRequestInterface $request): ?string
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }
}
