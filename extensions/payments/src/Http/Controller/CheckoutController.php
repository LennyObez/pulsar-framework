<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentMethod;
use Pulsar\Extension\Payments\Internal\Security\PciDssCompliance;
use Pulsar\Extension\Payments\Internal\Security\Psd2StrongAuth;
use Pulsar\Http\Message\Response;
use Throwable;

use function bin2hex;
use function is_int;
use function is_string;
use function random_bytes;

/**
 * Checkout controller for one-time payments.
 *
 * Handles the server-side checkout flow: create payment intent,
 * enforce PCI-DSS compliance, check SCA requirements.
 */
#[Internal]
final readonly class CheckoutController
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
    ) {}

    /**
     * POST /payments/checkout
     *
     * Create a payment intent for checkout.
     *
     * Expected JSON body:
     *   { "amount": 1000, "currency": "USD", "method": "card" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        // PCI-DSS: ensure no raw card numbers in the request
        try {
            PciDssCompliance::assertNoPan($body);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        $amountRaw = $body['amount'] ?? null;
        $amount = is_int($amountRaw) ? $amountRaw : (is_numeric($amountRaw) ? (int) $amountRaw : 0);
        $currencyCode = is_string($body['currency'] ?? null) ? strtoupper($body['currency']) : 'USD';
        $methodValue = is_string($body['method'] ?? null) ? $body['method'] : 'card';

        if ($amount <= 0) {
            return Response::json(['error' => 'Amount must be positive'], 422);
        }

        $currency = Currency::tryFrom($currencyCode);

        if ($currency === null) {
            return Response::json(['error' => "Unsupported currency: $currencyCode"], 422);
        }

        $method = PaymentMethod::tryFrom($methodValue);

        if ($method === null) {
            return Response::json(['error' => "Unsupported payment method: $methodValue"], 422);
        }

        $money = Money::of($amount, $currency);
        $idempotencyKey = 'checkout_' . bin2hex(random_bytes(16));

        // PSD2 SCA check
        $requiresSca = Psd2StrongAuth::requiresSca($money, $method);

        try {
            $intent = $this->gateway->createIntent($money, $idempotencyKey, [
                'method' => $method->value,
                'requires_sca' => $requiresSca,
            ]);

            return Response::json([
                'intent_id' => $intent->id,
                'amount' => $intent->amount->amount,
                'currency' => $intent->amount->currency->value,
                'status' => $intent->status->value,
                'requires_sca' => $requiresSca,
                'idempotency_key' => $idempotencyKey,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /payments/checkout/{intentId}/capture
     *
     * Capture a previously created payment intent.
     */
    public function capture(ServerRequestInterface $request): Response
    {
        $intentId = is_string($request->getAttribute('intentId')) ? $request->getAttribute('intentId') : '';

        if ($intentId === '') {
            return Response::json(['error' => 'Missing intent ID'], 400);
        }

        $idempotencyKey = 'capture_' . bin2hex(random_bytes(16));

        try {
            $charge = $this->gateway->captureIntent($intentId, $idempotencyKey);

            return Response::json([
                'charge_id' => $charge->id,
                'intent_id' => $charge->intentId,
                'amount' => $charge->amount->amount,
                'currency' => $charge->amount->currency->value,
                'status' => $charge->status->value,
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}
