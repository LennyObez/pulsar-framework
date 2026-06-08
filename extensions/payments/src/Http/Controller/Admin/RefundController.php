<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Http\Message\Response;
use Throwable;

use function bin2hex;
use function is_int;
use function is_string;
use function random_bytes;

/**
 * Admin controller for processing refunds.
 */
#[Internal]
final readonly class RefundController
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
    ) {}

    /**
     * POST /admin/payments/refunds
     *
     * Process a refund.
     *
     * Expected JSON body:
     *   { "charge_id": "...", "amount": 500, "currency": "USD" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawChargeId */
        $rawChargeId = $body['charge_id'] ?? null;
        $chargeId = is_string($rawChargeId) ? $rawChargeId : '';

        if ($chargeId === '') {
            return Response::json(['error' => 'charge_id is required'], 422);
        }

        $amount = null;

        if (isset($body['amount'])) {
            /** @var mixed $rawAmount */
            $rawAmount = $body['amount'];
            $amountValue = is_int($rawAmount) ? $rawAmount : (is_numeric($rawAmount) ? (int) $rawAmount : 0);
            /** @var mixed $rawCurrency */
            $rawCurrency = $body['currency'] ?? null;
            $currencyCode = is_string($rawCurrency) ? strtoupper($rawCurrency) : 'USD';
            $currency = Currency::tryFrom($currencyCode);

            if ($currency === null) {
                return Response::json(['error' => "Unsupported currency: $currencyCode"], 422);
            }

            $amount = Money::of($amountValue, $currency);
        }

        $idempotencyKey = 'refund_' . bin2hex(random_bytes(16));

        try {
            $refund = $this->gateway->refund($chargeId, $amount, $idempotencyKey);

            return Response::json([
                'status' => 'ok',
                'refund_id' => $refund->id,
                'charge_id' => $refund->chargeId,
                'amount' => $refund->amount->amount,
                'currency' => $refund->amount->currency->value,
                'refund_status' => $refund->status->value,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}
