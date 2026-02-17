<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Account;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Account\AccountSection;
use Pulsar\Extension\Cms\Account\AccountSectionProviderInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Extension\Payments\Internal\Persistence\DbPaymentRepository;

use function count;
use function htmlspecialchars;
use function intdiv;
use function is_int;
use function is_numeric;
use function sprintf;
use function str_replace;
use function ucfirst;

use const ENT_QUOTES;

/**
 * Contributes payment-related sections to the CMS customer account view.
 *
 * Registers three tabs:
 * - Orders (priority 10): paginated order list with status badges
 * - Invoices (priority 20): downloadable invoice list
 * - Payment Methods (priority 30): saved cards/methods with management
 *
 * Both front-office (customer-facing) and back-office (admin-facing)
 * renderers are provided. The front-office view is self-service; the
 * back-office view is read-only with admin actions (refund buttons, etc.).
 */
#[Internal(reason: 'AccountSectionProvider implementation; wired in composition root')]
final readonly class PaymentsAccountSectionProvider implements AccountSectionProviderInterface
{
    private const string SECTION_ORDERS = 'orders';
    private const string SECTION_INVOICES = 'invoices';
    private const string SECTION_PAYMENT_METHODS = 'payment-methods';

    private const int DEFAULT_PER_PAGE = 10;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private DbInvoiceRepository $invoiceRepository,
        private DbPaymentRepository $paymentRepository,
    ) {}

    public function getSections(string $userId): array
    {
        $orderResult = $this->orderRepository->findByCustomer($userId, 1, 1);
        $activeOrderCount = $this->countActiveOrders($userId);
        $invoices = $this->invoiceRepository->findByCustomer($userId);

        return [
            new AccountSection(
                id: self::SECTION_ORDERS,
                label: 'payments.account.orders',
                icon: 'shopping-bag',
                priority: 10,
                badgeCount: $activeOrderCount > 0 ? (string) $activeOrderCount : null,
            ),
            new AccountSection(
                id: self::SECTION_INVOICES,
                label: 'payments.account.invoices',
                icon: 'file-text',
                priority: 20,
                badgeCount: count($invoices) > 0 ? (string) count($invoices) : null,
            ),
            new AccountSection(
                id: self::SECTION_PAYMENT_METHODS,
                label: 'payments.account.payment_methods',
                icon: 'credit-card',
                priority: 30,
            ),
        ];
    }

    public function renderFrontOffice(string $sectionId, string $userId, array $params = []): string
    {
        return match ($sectionId) {
            self::SECTION_ORDERS => $this->renderFrontOfficeOrders($userId, $params),
            self::SECTION_INVOICES => $this->renderFrontOfficeInvoices($userId),
            self::SECTION_PAYMENT_METHODS => $this->renderFrontOfficePaymentMethods($userId),
            default => '<div class="pui-alert pui-alert--warning">Unknown section.</div>',
        };
    }

    public function renderBackOffice(string $sectionId, string $userId, array $params = []): string
    {
        return match ($sectionId) {
            self::SECTION_ORDERS => $this->renderBackOfficeOrders($userId, $params),
            self::SECTION_INVOICES => $this->renderBackOfficeInvoices($userId),
            self::SECTION_PAYMENT_METHODS => $this->renderBackOfficePaymentMethods($userId),
            default => '<div class="pui-alert pui-alert--warning">Unknown section.</div>',
        };
    }

    // -----------------------------------------------------------------------
    // Front-office renderers
    // -----------------------------------------------------------------------

    /**
     * Render paginated order list for the customer.
     *
     * @param array<string, mixed> $params
     */
    private function renderFrontOfficeOrders(string $userId, array $params): string
    {
        $page = $this->extractPage($params);
        $orders = $this->orderRepository->findByCustomer($userId, $page, self::DEFAULT_PER_PAGE);

        if ($orders === []) {
            return <<<'HTML'
                    <div class="pui-alert pui-alert--info">
                        <p>You have no orders yet.</p>
                    </div>
                HTML;
        }

        $rowsHtml = '';

        foreach ($orders as $order) {
            $safeNumber = $this->escape($order->orderNumber);
            $safeId = $this->escape($order->id);
            $statusBadge = $this->orderStatusBadge($order->status);
            $total = $this->formatMinorUnits($order->total, $order->currency);
            $date = $order->createdAt->format('M j, Y');

            $rowsHtml .= <<<HTML
                    <tr>
                        <td><a href="/account/orders/{$safeId}">{$safeNumber}</a></td>
                        <td>{$date}</td>
                        <td>{$statusBadge}</td>
                        <td>{$total}</td>
                        <td><a href="/account/orders/{$safeId}" class="pui-btn pui-btn--sm">View</a></td>
                    </tr>
                HTML;
        }

        $pagination = '';

        return <<<HTML
                <table class="pui-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
                {$pagination}
            HTML;
    }

    /**
     * Render downloadable invoice list for the customer.
     */
    private function renderFrontOfficeInvoices(string $userId): string
    {
        $invoices = $this->invoiceRepository->findByCustomer($userId);

        if ($invoices === []) {
            return <<<'HTML'
                    <div class="pui-alert pui-alert--info">
                        <p>You have no invoices yet.</p>
                    </div>
                HTML;
        }

        $rowsHtml = '';

        foreach ($invoices as $invoice) {
            $safeNumber = $this->escape($invoice->invoiceNumber);
            $safeId = $this->escape($invoice->id);
            $statusBadge = $this->invoiceStatusBadge($invoice->status->value);
            $total = sprintf(
                '%s %s',
                $invoice->total->currency->symbol(),
                $invoice->total->format(),
            );
            $date = $invoice->createdAt->format('M j, Y');
            $paidAt = $invoice->paidAt?->format('M j, Y') ?? '-';

            $downloadButton = sprintf(
                '<a href="/payments/invoices/%s/download" class="pui-btn pui-btn--sm pui-btn--outline" '
                . 'title="Download invoice">Download</a>',
                $safeId,
            );

            $rowsHtml .= <<<HTML
                    <tr>
                        <td>{$safeNumber}</td>
                        <td>{$date}</td>
                        <td>{$statusBadge}</td>
                        <td>{$total}</td>
                        <td>{$paidAt}</td>
                        <td>{$downloadButton}</td>
                    </tr>
                HTML;
        }

        return <<<HTML
                <table class="pui-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
            HTML;
    }

    /**
     * Render saved payment methods for customer self-service.
     */
    private function renderFrontOfficePaymentMethods(string $userId): string
    {
        $payments = $this->paymentRepository->findByCustomer($userId);

        // Deduplicate by method type to show unique payment methods used
        $seenMethods = [];
        $uniqueMethods = [];

        foreach ($payments as $payment) {
            $key = $payment->method->value . '_' . $payment->gateway;

            if (!isset($seenMethods[$key])) {
                $seenMethods[$key] = true;
                $uniqueMethods[] = $payment;
            }
        }

        if ($uniqueMethods === []) {
            return <<<'HTML'
                    <div class="pui-alert pui-alert--info">
                        <p>You have no saved payment methods. A payment method will appear here after your first purchase.</p>
                    </div>
                HTML;
        }

        $cardsHtml = '';

        foreach ($uniqueMethods as $payment) {
            $methodLabel = $this->paymentMethodLabel($payment->method->value);
            $gateway = $this->escape($payment->gateway);
            $icon = $this->paymentMethodIcon($payment->method->value);

            $cardsHtml .= <<<HTML
                    <div class="pui-card">
                        <div class="pui-card__body">
                            <div class="pui-flex pui-items-center pui-gap-sm">
                                <span class="pui-icon">{$icon}</span>
                                <div>
                                    <strong>{$methodLabel}</strong>
                                    <div class="pui-text-sm pui-text-muted">via {$gateway}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                HTML;
        }

        return <<<HTML
                <div class="pui-grid pui-grid--2">
                    {$cardsHtml}
                </div>
                <div class="pui-mt-md">
                    <p class="pui-text-sm pui-text-muted">
                        Payment methods are managed through your payment provider.
                        Contact support to update or remove a saved method.
                    </p>
                </div>
            HTML;
    }

    // -----------------------------------------------------------------------
    // Back-office renderers
    // -----------------------------------------------------------------------

    /**
     * Render full order history for admin customer detail view.
     *
     * @param array<string, mixed> $params
     */
    private function renderBackOfficeOrders(string $userId, array $params): string
    {
        $page = $this->extractPage($params);
        $orders = $this->orderRepository->findByCustomer($userId, $page, self::DEFAULT_PER_PAGE);

        if ($orders === []) {
            return '<div class="pui-alert pui-alert--info"><p>No orders for this customer.</p></div>';
        }

        $rowsHtml = '';

        foreach ($orders as $order) {
            $safeNumber = $this->escape($order->orderNumber);
            $safeId = $this->escape($order->id);
            $statusBadge = $this->orderStatusBadge($order->status);
            $total = $this->formatMinorUnits($order->total, $order->currency);
            $subtotal = $this->formatMinorUnits($order->subtotal, $order->currency);
            $tax = $this->formatMinorUnits($order->taxAmount, $order->currency);
            $shipping = $this->formatMinorUnits($order->shippingAmount, $order->currency);
            $date = $order->createdAt->format('Y-m-d H:i');
            $paymentBadge = $this->paymentStatusBadge($order->paymentStatus->value);

            $refundButton = '';

            if ($order->paymentStatus->isPaid() && !$order->isCancelled() && $order->amountRefunded < $order->total) {
                $refundButton = sprintf(
                    '<button class="pui-btn pui-btn--sm pui-btn--warning" '
                    . 'data-action="refund" data-order-id="%s">Refund</button>',
                    $safeId,
                );
            }

            $refundedNote = $order->amountRefunded > 0
                ? sprintf(
                    ' <span class="pui-badge pui-badge--warning">Refunded: %s</span>',
                    $this->formatMinorUnits($order->amountRefunded, $order->currency),
                )
                : '';

            $rowsHtml .= <<<HTML
                    <tr>
                        <td><a href="/admin/orders/{$safeId}">{$safeNumber}</a></td>
                        <td>{$date}</td>
                        <td>{$statusBadge}</td>
                        <td>{$paymentBadge}</td>
                        <td>{$subtotal}</td>
                        <td>{$tax}</td>
                        <td>{$shipping}</td>
                        <td>{$total}{$refundedNote}</td>
                        <td>{$refundButton}</td>
                    </tr>
                HTML;
        }

        $pagination = '';

        return <<<HTML
                <table class="pui-table pui-table--striped">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Shipping</th>
                            <th>Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
                {$pagination}
            HTML;
    }

    /**
     * Render all invoices for admin customer detail view.
     */
    private function renderBackOfficeInvoices(string $userId): string
    {
        $invoices = $this->invoiceRepository->findByCustomer($userId);

        if ($invoices === []) {
            return '<div class="pui-alert pui-alert--info"><p>No invoices for this customer.</p></div>';
        }

        $rowsHtml = '';

        foreach ($invoices as $invoice) {
            $safeNumber = $this->escape($invoice->invoiceNumber);
            $safeId = $this->escape($invoice->id);
            $statusBadge = $this->invoiceStatusBadge($invoice->status->value);
            $subtotal = sprintf('%s %s', $invoice->subtotal->currency->symbol(), $invoice->subtotal->format());
            $tax = sprintf('%s %s', $invoice->tax->currency->symbol(), $invoice->tax->format());
            $total = sprintf('%s %s', $invoice->total->currency->symbol(), $invoice->total->format());
            $date = $invoice->createdAt->format('Y-m-d H:i');
            $paidAt = $invoice->paidAt?->format('Y-m-d H:i') ?? '-';
            $dueDate = $invoice->dueDate?->format('Y-m-d') ?? '-';
            $lineItemCount = count($invoice->lineItems);

            $downloadLink = sprintf(
                '<a href="/payments/invoices/%s/download" class="pui-btn pui-btn--sm">Download</a>',
                $safeId,
            );

            $rowsHtml .= <<<HTML
                    <tr>
                        <td>{$safeNumber}</td>
                        <td>{$date}</td>
                        <td>{$statusBadge}</td>
                        <td>{$subtotal}</td>
                        <td>{$tax}</td>
                        <td>{$total}</td>
                        <td>{$dueDate}</td>
                        <td>{$paidAt}</td>
                        <td>{$lineItemCount} items</td>
                        <td>{$downloadLink}</td>
                    </tr>
                HTML;
        }

        return <<<HTML
                <table class="pui-table pui-table--striped">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Total</th>
                            <th>Due</th>
                            <th>Paid</th>
                            <th>Items</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
            HTML;
    }

    /**
     * Render a read-only view of customer payment methods for admin.
     */
    private function renderBackOfficePaymentMethods(string $userId): string
    {
        $payments = $this->paymentRepository->findByCustomer($userId);

        if ($payments === []) {
            return '<div class="pui-alert pui-alert--info"><p>No payment methods on file.</p></div>';
        }

        // Show unique methods with most recent payment date
        $methodMap = [];

        foreach ($payments as $payment) {
            $key = $payment->method->value . '_' . $payment->gateway;

            if (!isset($methodMap[$key])) {
                $methodMap[$key] = [
                    'method' => $payment->method->value,
                    'gateway' => $payment->gateway,
                    'last_used' => $payment->createdAt,
                    'payment_count' => 1,
                    'total_spent' => $payment->amount->amount,
                    'currency' => $payment->amount->currency,
                ];
            } else {
                $methodMap[$key]['payment_count']++;
                $methodMap[$key]['total_spent'] += $payment->amount->amount;

                if ($payment->createdAt > $methodMap[$key]['last_used']) {
                    $methodMap[$key]['last_used'] = $payment->createdAt;
                }
            }
        }

        $rowsHtml = '';

        foreach ($methodMap as $entry) {
            $methodLabel = $this->paymentMethodLabel($entry['method']);
            $gateway = $this->escape($entry['gateway']);
            $lastUsed = $entry['last_used']->format('Y-m-d H:i');
            $paymentCount = $entry['payment_count'];
            $totalSpent = $this->formatMinorUnitsWithCurrency($entry['total_spent'], $entry['currency']);

            $rowsHtml .= <<<HTML
                    <tr>
                        <td>{$methodLabel}</td>
                        <td>{$gateway}</td>
                        <td>{$lastUsed}</td>
                        <td>{$paymentCount}</td>
                        <td>{$totalSpent}</td>
                    </tr>
                HTML;
        }

        return <<<HTML
                <table class="pui-table pui-table--striped">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Gateway</th>
                            <th>Last Used</th>
                            <th>Payments</th>
                            <th>Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
            HTML;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Count orders in non-terminal statuses for badge display.
     */
    private function countActiveOrders(string $userId): int
    {
        $orders = $this->orderRepository->findByCustomer($userId, 1, 100);
        $count = 0;

        foreach ($orders as $order) {
            if (!$order->status->isTerminal() && $order->status !== OrderStatus::Cart) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Render an order status as a colored badge.
     */
    private function orderStatusBadge(OrderStatus $status): string
    {
        $variant = match ($status) {
            OrderStatus::Cart => 'secondary',
            OrderStatus::PendingPayment => 'warning',
            OrderStatus::Confirmed => 'info',
            OrderStatus::Fulfilled => 'success',
            OrderStatus::Refunded => 'warning',
            OrderStatus::Failed => 'danger',
            OrderStatus::Cancelled => 'secondary',
        };

        $label = $this->escape($status->label());

        return sprintf('<span class="pui-badge pui-badge--%s">%s</span>', $variant, $label);
    }

    /**
     * Render an invoice status as a colored badge.
     */
    private function invoiceStatusBadge(string $status): string
    {
        $variant = match ($status) {
            'draft' => 'secondary',
            'open' => 'info',
            'paid' => 'success',
            'voided' => 'warning',
            'uncollectible' => 'danger',
            default => 'secondary',
        };

        $label = $this->escape(ucfirst($status));

        return sprintf('<span class="pui-badge pui-badge--%s">%s</span>', $variant, $label);
    }

    /**
     * Render a payment status as a colored badge.
     */
    private function paymentStatusBadge(string $status): string
    {
        $variant = match ($status) {
            'pending' => 'warning',
            'paid', 'captured' => 'success',
            'failed' => 'danger',
            'refunded', 'partially_refunded' => 'warning',
            default => 'secondary',
        };

        $label = $this->escape(ucfirst(str_replace('_', ' ', $status)));

        return sprintf('<span class="pui-badge pui-badge--%s">%s</span>', $variant, $label);
    }

    /**
     * Get a human-readable label for a payment method type.
     */
    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'card' => 'Credit/Debit Card',
            'sepa' => 'SEPA Direct Debit',
            'paypal' => 'PayPal',
            'bank_transfer' => 'Bank Transfer',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'bancontact' => 'Bancontact',
            'ideal' => 'iDEAL',
            'klarna_pay_later' => 'Klarna Pay Later',
            'klarna_pay_now' => 'Klarna Pay Now',
            'klarna_slice_it' => 'Klarna Slice It',
            'payconiq' => 'Payconiq',
            'epc_qr' => 'EPC QR Payment',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    /**
     * Get an icon identifier for a payment method.
     */
    private function paymentMethodIcon(string $method): string
    {
        return match ($method) {
            'card' => 'credit-card',
            'paypal' => 'paypal',
            'apple_pay' => 'apple',
            'google_pay' => 'google',
            'bank_transfer', 'sepa' => 'bank',
            default => 'wallet',
        };
    }

    /**
     * Format minor-unit currency amount for display.
     */
    private function formatMinorUnits(int $amount, string $currencyCode): string
    {
        $currency = \Pulsar\Extension\Payments\Domain\Currency::tryFrom($currencyCode);

        if ($currency === null) {
            $digits = 2;
            $symbol = $currencyCode;
        } else {
            $digits = $currency->minorDigits();
            $symbol = $currency->symbol();
        }

        if ($digits === 0) {
            return $symbol . (string) $amount;
        }

        $divisor = 10 ** $digits;
        $whole = intdiv($amount, $divisor);
        $fraction = $amount % $divisor;

        return sprintf('%s%d.%0' . $digits . 'd', $symbol, $whole, $fraction);
    }

    /**
     * Format with a Currency enum instance.
     */
    private function formatMinorUnitsWithCurrency(int $amount, \Pulsar\Extension\Payments\Domain\Currency $currency): string
    {
        return $this->formatMinorUnits($amount, $currency->value);
    }

    /**
     * Extract the page number from params.
     *
     * @param array<string, mixed> $params
     */
    private function extractPage(array $params): int
    {
        $page = $params['page'] ?? 1;

        if (is_int($page) && $page >= 1) {
            return $page;
        }

        if (is_numeric($page)) {
            $int = (int) $page;

            return $int >= 1 ? $int : 1;
        }

        return 1;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
