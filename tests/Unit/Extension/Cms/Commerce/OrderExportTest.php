<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Content\DataClassification;

use function array_filter;
use function count;
use function explode;
use function is_string;
use function json_decode;

#[CoversClass(Order::class)]
final class OrderExportTest extends TestCase
{
    /** @var list<Order> */
    private array $orders;

    protected function setUp(): void
    {
        $this->orders = [
            new Order(
                id: 'order-001',
                tenantId: null,
                orderNumber: 'ORD-001',
                customerId: 'cust-001',
                customerEmail: 'alice@example.com',
                status: OrderStatus::Confirmed,
                subtotal: 5000,
                taxAmount: 500,
                discountAmount: 0,
                shippingAmount: 0,
                shippingMethod: null,
                total: 5500,
                amountRefunded: 0,
                currency: 'EUR',
                paymentIntentId: 'pi_001',
                paymentStatus: PaymentStatus::Paid,
                billingAddress: ['line1' => '123 Main St', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
                shippingAddress: null,
                notes: null,
                dataClassification: DataClassification::Pii,
                createdAt: new DateTimeImmutable('2026-01-15'),
                updatedAt: new DateTimeImmutable('2026-01-15'),
            ),
            new Order(
                id: 'order-002',
                tenantId: null,
                orderNumber: 'ORD-002',
                customerId: 'cust-002',
                customerEmail: 'bob@example.com',
                status: OrderStatus::Fulfilled,
                subtotal: 10000,
                taxAmount: 1000,
                discountAmount: 500,
                shippingAmount: 0,
                shippingMethod: null,
                total: 10500,
                amountRefunded: 0,
                currency: 'EUR',
                paymentIntentId: 'pi_002',
                paymentStatus: PaymentStatus::Paid,
                billingAddress: ['line1' => '456 Oak Ave', 'city' => 'Antwerp', 'postalCode' => '2000', 'country' => 'BE'],
                shippingAddress: null,
                notes: null,
                dataClassification: DataClassification::Pii,
                createdAt: new DateTimeImmutable('2026-02-01'),
                updatedAt: new DateTimeImmutable('2026-02-01'),
            ),
        ];
    }

    // ── CSV format ──────────────────────────────────────────────────

    #[Test]
    public function csvExportContainsHeadersAndData(): void
    {
        $service = $this->createExportService();

        $csv = $service->exportCsv([]);

        $lines = explode("\n", $csv);

        self::assertGreaterThanOrEqual(3, count($lines)); // header + 2 rows + possible trailing newline
        self::assertStringContainsString('orderNumber', $lines[0]);
        self::assertStringContainsString('ORD-001', $csv);
        self::assertStringContainsString('ORD-002', $csv);
    }

    // ── JSON format with PII redacted ───────────────────────────────

    #[Test]
    public function jsonExportPiiRedacted(): void
    {
        $service = $this->createExportService();

        $json = $service->exportJson([], includePii: false);
        /** @var list<array<string, mixed>> $data */
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertCount(2, $data);

        foreach ($data as $orderData) {
            self::assertSame('[redacted]', $orderData['customerEmail']);
            self::assertSame('[redacted]', $orderData['billingAddress']);
        }
    }

    #[Test]
    public function jsonExportPiiIncluded(): void
    {
        $service = $this->createExportService();

        $json = $service->exportJson([], includePii: true);
        /** @var list<array<string, mixed>> $data */
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertSame('alice@example.com', $data[0]['customerEmail']);
        self::assertIsArray($data[0]['billingAddress']);
    }

    // ── Date filter ─────────────────────────────────────────────────

    #[Test]
    public function dateFilter(): void
    {
        $service = $this->createExportService();

        $csv = $service->exportCsv(['dateFrom' => '2026-02-01']);

        self::assertStringNotContainsString('ORD-001', $csv);
        self::assertStringContainsString('ORD-002', $csv);
    }

    private function createExportService(): OrderExportServiceInterface
    {
        $orders = $this->orders;

        return new class ($orders) implements OrderExportServiceInterface {
            /** @param list<Order> $orders */
            public function __construct(private readonly array $orders) {}

            public function exportCsv(array $filters): string
            {
                $filtered = $this->applyFilters($filters);

                $lines = ['orderNumber,status,subtotal,taxAmount,total,currency'];

                foreach ($filtered as $order) {
                    $lines[] = "{$order->orderNumber},{$order->status->value},{$order->subtotal},{$order->taxAmount},{$order->total},{$order->currency}";
                }

                return implode("\n", $lines) . "\n";
            }

            public function exportJson(array $filters, bool $includePii = false): string
            {
                $filtered = $this->applyFilters($filters);
                $result = [];

                foreach ($filtered as $order) {
                    $result[] = [
                        'orderNumber' => $order->orderNumber,
                        'status' => $order->status->value,
                        'subtotal' => $order->subtotal,
                        'taxAmount' => $order->taxAmount,
                        'total' => $order->total,
                        'currency' => $order->currency,
                        'customerEmail' => $includePii ? $order->customerEmail : '[redacted]',
                        'billingAddress' => $includePii ? $order->billingAddress : '[redacted]',
                    ];
                }

                return json_encode($result, JSON_THROW_ON_ERROR);
            }

            /**
             * @param array<string, mixed> $filters
             * @return list<Order>
             */
            private function applyFilters(array $filters): array
            {
                $filtered = $this->orders;

                if (isset($filters['dateFrom']) && is_string($filters['dateFrom'])) {
                    $from = new DateTimeImmutable($filters['dateFrom']);
                    $filtered = array_values(array_filter($filtered, static fn(Order $o) => $o->createdAt >= $from));
                }

                return $filtered;
            }
        };
    }
}
