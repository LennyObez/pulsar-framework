<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Extension\Payments\Internal\Persistence\DbPaymentRepository;
use Pulsar\Extension\Payments\Internal\Persistence\DbSubscriptionRepository;
use Pulsar\Http\Message\Response;

use function htmlspecialchars;
use function str_contains;

use const ENT_QUOTES;

/**
 * Admin dashboard controller for payment overview.
 *
 * Returns HTML for browser requests and JSON for API clients.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class PaymentDashboardController
{
    public function __construct(
        private DbPaymentRepository $paymentRepository,
        private DbSubscriptionRepository $subscriptionRepository,
        private DbInvoiceRepository $invoiceRepository,
    ) {}

    /**
     * GET /admin/payments
     *
     * Payment dashboard overview.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === 'application/json' || str_contains($accept, 'application/json')) {
            return Response::json([
                'title' => 'Payment Dashboard',
                'message' => 'Payment administration panel',
                'repositories' => [
                    'payments' => $this->paymentRepository::class,
                    'subscriptions' => $this->subscriptionRepository::class,
                    'invoices' => $this->invoiceRepository::class,
                ],
            ]);
        }

        $paymentsClass = htmlspecialchars($this->paymentRepository::class, ENT_QUOTES, 'UTF-8');
        $subscriptionsClass = htmlspecialchars($this->subscriptionRepository::class, ENT_QUOTES, 'UTF-8');
        $invoicesClass = htmlspecialchars($this->invoiceRepository::class, ENT_QUOTES, 'UTF-8');

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en" data-theme="dark">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Payment Dashboard</title>
                    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
                </head>
                <body>
                    <nav class="pui-navbar">
                        <a href="/admin" class="pui-navbar__brand">Admin</a>
                        <div class="pui-navbar__links">
                            <a href="/admin/payments" class="active">Payments</a>
                            <a href="/admin/payments/subscriptions">Subscriptions</a>
                            <a href="/admin/payments/invoices">Invoices</a>
                        </div>
                    </nav>
                    <main class="pui-container">
                        <h1>Payment Dashboard</h1>
                        <p>Payment administration panel.</p>
                        <div class="pui-grid pui-grid--3">
                            <div class="pui-card">
                                <h3 class="pui-card__title">Payments</h3>
                                <p class="pui-card__text"><code>$paymentsClass</code></p>
                            </div>
                            <div class="pui-card">
                                <h3 class="pui-card__title">Subscriptions</h3>
                                <p class="pui-card__text"><code>$subscriptionsClass</code></p>
                            </div>
                            <div class="pui-card">
                                <h3 class="pui-card__title">Invoices</h3>
                                <p class="pui-card__text"><code>$invoicesClass</code></p>
                            </div>
                        </div>
                    </main>
                </body>
                </html>
                HTML,
        );
    }
}
