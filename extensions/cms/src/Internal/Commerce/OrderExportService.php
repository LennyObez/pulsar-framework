<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;
use function hash_hmac;
use function implode;
use function json_encode;
use function str_contains;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Exports order data as CSV or JSON with optional PII redaction and evidence hashing.
 *
 * @psalm-api Bound to OrderExportServiceInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use OrderExportServiceInterface for public API')]
final readonly class OrderExportService implements OrderExportServiceInterface
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private OrderItemRepositoryInterface $orderItems,
        private CmsKeyManager $keyManager,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function exportCsv(array $filters): string
    {
        $orders = $this->orders->listOrders($filters, 1, 10000);

        $rows = [];
        $rows[] = implode(',', [
            'order_number', 'date', 'customer_email', 'subtotal',
            'tax', 'discount', 'total', 'status', 'payment_status', 'items_summary',
        ]);

        foreach ($orders as $order) {
            $items = $this->orderItems->findByOrder($order->id);
            $itemsSummary = count($items) . ' item(s)';

            $rows[] = implode(',', [
                $this->csvEscape($order->orderNumber),
                $this->csvEscape($order->createdAt->format('c')),
                $this->csvEscape($order->customerEmail),
                (string) $order->subtotal,
                (string) $order->taxAmount,
                (string) $order->discountAmount,
                (string) $order->total,
                $this->csvEscape($order->status->value),
                $this->csvEscape($order->paymentStatus->value),
                $this->csvEscape($itemsSummary),
            ]);
        }

        return implode("\n", $rows) . "\n";
    }

    public function exportJson(array $filters, bool $includePii = false): string
    {
        $orders = $this->orders->listOrders($filters, 1, 10000);

        $exportData = [];

        foreach ($orders as $order) {
            $items = $this->orderItems->findByOrder($order->id);

            $orderData = [
                'orderNumber' => $order->orderNumber,
                'date' => $order->createdAt->format('c'),
                'status' => $order->status->value,
                'paymentStatus' => $order->paymentStatus->value,
                'subtotal' => $order->subtotal,
                'taxAmount' => $order->taxAmount,
                'discountAmount' => $order->discountAmount,
                'total' => $order->total,
                'currency' => $order->currency,
                'items' => [],
            ];

            if ($includePii) {
                $orderData['customerEmail'] = $order->customerEmail;
                $orderData['customerId'] = $order->customerId;
                $orderData['billingAddress'] = $order->billingAddress;
                $orderData['shippingAddress'] = $order->shippingAddress;
            } else {
                $orderData['customerEmail'] = '[REDACTED]';
                $orderData['billingAddress'] = ['country' => $order->billingAddress['country'] ?? '[REDACTED]'];
                $orderData['shippingAddress'] = null;
            }

            foreach ($items as $item) {
                $orderData['items'][] = [
                    'productId' => $item->productId,
                    'quantity' => $item->quantity,
                    'unitPrice' => $item->unitPrice,
                    'totalPrice' => $item->totalPrice,
                    'taxAmount' => $item->taxAmount,
                    'discountAmount' => $item->discountAmount,
                ];
            }

            $exportData[] = $orderData;
        }

        $jsonContent = json_encode($exportData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Compute evidence hash using HMAC-SHA256
        $evidenceHash = hash_hmac('sha256', $jsonContent, $this->keyManager->evidenceKey());

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.commerce.orders.exported',
            'orders:export',
            [
                'format' => 'json',
                'orderCount' => count($exportData),
                'includePii' => $includePii,
                'evidenceHash' => $evidenceHash,
            ],
        );

        // Wrap export with metadata
        $envelope = [
            'version' => '1.0',
            'exportedAt' => new DateTimeImmutable()->format('c'),
            'evidenceHash' => $evidenceHash,
            'orderCount' => count($exportData),
            'orders' => $exportData,
        ];

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
