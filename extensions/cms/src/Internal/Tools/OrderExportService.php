<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;

use function array_map;
use function bin2hex;
use function count;
use function fclose;
use function fopen;
use function fputcsv;
use function is_int;
use function is_string;
use function json_encode;
use function number_format;
use function rewind;
use function sodium_crypto_generichash;
use function stream_get_contents;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Order data export service supporting CSV and JSON output.
 *
 * Filters by date range, status, and tenant. PII redaction
 * is applied by default in JSON exports unless explicitly included.
 */
/**
 * @psalm-api Bound to OrderExportServiceInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Order export internals; use OrderExportServiceInterface')]
final readonly class OrderExportService implements OrderExportServiceInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderItemRepositoryInterface $orderItemRepository,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function exportCsv(array $filters): string
    {
        $orders = $this->fetchOrders($filters);

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Failed to open temporary stream for CSV export');
        }

        // CSV header
        fputcsv($stream, [
            'order_number',
            'status',
            'customer_id',
            'customer_email',
            'subtotal',
            'tax',
            'discount',
            'total',
            'currency',
            'payment_status',
            'items_count',
            'created_at',
        ]);

        foreach ($orders as $order) {
            $items = $this->orderItemRepository->findByOrder($order->id);

            fputcsv($stream, [
                $order->orderNumber,
                $order->status->value,
                $order->customerId,
                $order->customerEmail,
                $this->formatMinorUnits($order->subtotal),
                $this->formatMinorUnits($order->taxAmount),
                $this->formatMinorUnits($order->discountAmount),
                $this->formatMinorUnits($order->total),
                $order->currency,
                $order->paymentStatus->value,
                count($items),
                $order->createdAt->format('c'),
            ]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.order_export.csv',
            'cms:order_export',
            [
                'format' => 'csv',
                'order_count' => count($orders),
                'filters' => $this->sanitizeFiltersForLog($filters),
            ],
        );

        return $csv;
    }

    public function exportJson(array $filters, bool $includePii = false): string
    {
        $orders = $this->fetchOrders($filters);

        $exportData = array_map(function (Order $order) use ($includePii): array {
            $items = $this->orderItemRepository->findByOrder($order->id);

            return [
                'order_number' => $order->orderNumber,
                'status' => $order->status->value,
                'customer_id' => $order->customerId,
                'customer_email' => $includePii ? $order->customerEmail : '[redacted]',
                'subtotal' => $order->subtotal,
                'tax_amount' => $order->taxAmount,
                'discount_amount' => $order->discountAmount,
                'total' => $order->total,
                'currency' => $order->currency,
                'payment_intent_id' => $order->paymentIntentId,
                'payment_status' => $order->paymentStatus->value,
                'billing_address' => $includePii ? $order->billingAddress : '[redacted]',
                'shipping_address' => $includePii ? $order->shippingAddress : ($order->shippingAddress !== null ? '[redacted]' : null),
                'notes' => $order->notes,
                'created_at' => $order->createdAt->format('c'),
                'updated_at' => $order->updatedAt->format('c'),
                'items' => array_map(static fn(object $item): array => [
                    'product_id' => $item->productId,
                    'variant_id' => $item->variantId,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'total_price' => $item->totalPrice,
                    'tax_amount' => $item->taxAmount,
                    'discount_amount' => $item->discountAmount,
                    'product_snapshot' => $item->productSnapshot,
                ], $items),
            ];
        }, $orders);

        $json = json_encode([
            'export' => [
                'format' => 'json',
                'version' => '1.0',
                'exported_at' => new DateTimeImmutable()->format('c'),
                'pii_included' => $includePii,
                'order_count' => count($exportData),
                'filters' => $this->sanitizeFiltersForLog($filters),
            ],
            'orders' => $exportData,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.order_export.json',
            'cms:order_export',
            [
                'format' => 'json',
                'order_count' => count($exportData),
                'pii_included' => $includePii,
                'evidence_hash' => bin2hex(sodium_crypto_generichash($json)),
                'filters' => $this->sanitizeFiltersForLog($filters),
            ],
        );

        return $json;
    }

    /**
     * Fetch orders from the repository using the given filters.
     *
     * @param array{
     *     status?: string,
     *     tenantId?: string,
     *     dateFrom?: string,
     *     dateTo?: string,
     *     customerId?: string,
     *     page?: int,
     *     perPage?: int,
     * } $filters
     *
     * @return list<Order>
     */
    private function fetchOrders(array $filters): array
    {
        $repoFilters = [];

        $statusFilter = $filters['status'] ?? '';
        if ($statusFilter !== '') {
            $status = OrderStatus::tryFrom($statusFilter);

            if ($status !== null) {
                $repoFilters['status'] = $status;
            }
        }

        if (isset($filters['tenantId'])) {
            $repoFilters['tenantId'] = $filters['tenantId'];
        }

        if (isset($filters['dateFrom'])) {
            $repoFilters['dateFrom'] = $filters['dateFrom'];
        }

        if (isset($filters['dateTo'])) {
            $repoFilters['dateTo'] = $filters['dateTo'];
        }

        if (isset($filters['customerId'])) {
            $repoFilters['customerId'] = $filters['customerId'];
        }

        $page = $filters['page'] ?? 1;
        $perPage = $filters['perPage'] ?? 10000;

        return $this->orderRepository->listOrders($repoFilters, $page, $perPage);
    }

    /**
     * Format minor currency units (cents) to a decimal string.
     */
    private function formatMinorUnits(int $amount): string
    {
        return number_format((float) $amount / 100.0, 2, '.', '');
    }

    /**
     * Remove sensitive values from filter array before logging.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function sanitizeFiltersForLog(array $filters): array
    {
        /** @var array<string, mixed> $safe */
        /** @var array<string, mixed> $safe */
        $safe = [];

        /** @var mixed $value */
        foreach ($filters as $key => $value) {
            if ($key === 'customerId' || $key === 'tenantId') {
                $safe[$key] = '[present]';
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
