<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin controller for order management.
 *
 * All actions require CMS commerce permissions checked via GateInterface.
 * Refund operations require step-up authentication.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class OrderController
{
    use RendersAdminView;

    public function __construct(
        private OrderRepositoryInterface $orders,
        private OrderItemRepositoryInterface $orderItems,
        private OrderService $orderService,
        private OrderExportServiceInterface $exportService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.view');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        /** @var array<string, mixed> $filters */
        $filters = [];

        if (is_string($params['status'] ?? null) && $params['status'] !== '') {
            $filters['status'] = $params['status'];
        }

        if (is_string($params['date_from'] ?? null) && $params['date_from'] !== '') {
            $filters['dateFrom'] = $params['date_from'];
        }

        if (is_string($params['date_to'] ?? null) && $params['date_to'] !== '') {
            $filters['dateTo'] = $params['date_to'];
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId !== null) {
            $filters['tenantId'] = $tenantId;
        }

        $orders = $this->orders->listOrders($filters, $page, $perPage);

        return $this->respondWithView($request, 'admin.orders.index', [
            'data' => array_map(static fn(Order $o) => [
                'id' => $o->id,
                'order_number' => $o->orderNumber,
                'customer_email' => $o->customerEmail,
                'status' => $o->status->value,
                'payment_status' => $o->paymentStatus->value,
                'total' => $o->total,
                'currency' => $o->currency,
                'created_at' => $o->createdAt->format('c'),
            ], $orders),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.view');

        $order = $this->orders->findById($id);

        if ($order === null) {
            return Response::json(['error' => 'Order not found'], 404);
        }

        $items = $this->orderItems->findByOrder($id);

        return $this->respondWithView($request, 'admin.orders.show', [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->orderNumber,
                'customer_id' => $order->customerId,
                'customer_email' => $order->customerEmail,
                'status' => $order->status->value,
                'payment_status' => $order->paymentStatus->value,
                'subtotal' => $order->subtotal,
                'tax_amount' => $order->taxAmount,
                'discount_amount' => $order->discountAmount,
                'total' => $order->total,
                'currency' => $order->currency,
                'payment_intent_id' => $order->paymentIntentId,
                'billing_address' => $order->billingAddress,
                'shipping_address' => $order->shippingAddress,
                'notes' => $order->notes,
                'created_at' => $order->createdAt->format('c'),
                'updated_at' => $order->updatedAt->format('c'),
            ],
            'items' => array_map(static fn($item) => [
                'id' => $item->id,
                'product_id' => $item->productId,
                'variant_id' => $item->variantId,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'total_price' => $item->totalPrice,
                'tax_amount' => $item->taxAmount,
                'discount_amount' => $item->discountAmount,
                'product_snapshot' => $item->productSnapshot,
            ], $items),
        ]);
    }

    public function refund(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.refund');
        $this->requireStepUp($request);

        $order = $this->orders->findById($id);

        if ($order === null) {
            return Response::json(['error' => 'Order not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $amount = (int) ($body['amount'] ?? 0);
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if ($amount <= 0) {
            return Response::json(['error' => 'Refund amount must be positive'], 400);
        }

        if ($reason === '') {
            return Response::json(['error' => 'Reason is required for refunds'], 400);
        }

        try {
            $this->orderService->refund($id, $amount, $reason, $identity->id());

            return Response::json([
                'order_id' => $id,
                'refund_amount' => $amount,
                'status' => 'refunded',
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function export(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.orders.export');

        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();
        $format = (string) ($params['format'] ?? '');

        // GET with no format renders the export form data
        if ($format === '') {
            return Response::json([
                'formats' => ['csv', 'json'],
                'filters' => [
                    'date_from' => null,
                    'date_to' => null,
                    'status' => null,
                ],
            ]);
        }

        /** @var array<string, mixed> $filters */
        $filters = [];

        if (is_string($params['status'] ?? null) && $params['status'] !== '') {
            $filters['status'] = $params['status'];
        }

        if (is_string($params['date_from'] ?? null) && $params['date_from'] !== '') {
            $filters['dateFrom'] = $params['date_from'];
        }

        if (is_string($params['date_to'] ?? null) && $params['date_to'] !== '') {
            $filters['dateTo'] = $params['date_to'];
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId !== null) {
            $filters['tenantId'] = $tenantId;
        }

        $includePii = ($params['include_pii'] ?? '0') === '1';

        if ($format === 'csv') {
            $content = $this->exportService->exportCsv($filters);

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="orders-export.csv"',
                ],
                body: $content,
            );
        }

        if ($format === 'json') {
            $content = $this->exportService->exportJson($filters, $includePii);

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="orders-export.json"',
                ],
                body: $content,
            );
        }

        return Response::json(['error' => 'Unsupported export format'], 400);
    }

}
