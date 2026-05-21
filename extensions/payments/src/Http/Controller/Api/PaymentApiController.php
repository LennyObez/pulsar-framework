<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Internal\Persistence\DbPaymentRepository;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Payment API controller for querying payment records.
 */
#[Internal]
final readonly class PaymentApiController
{
    public function __construct(
        private DbPaymentRepository $paymentRepository,
    ) {}

    /**
     * GET /api/v1/payments/{id}
     *
     * Retrieve a payment by ID.
     */
    public function show(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawPaymentId */
        $rawPaymentId = $request->getAttribute('id');
        $paymentId = is_string($rawPaymentId) ? $rawPaymentId : '';

        if ($paymentId === '') {
            return Response::json(['error' => 'Missing payment ID'], 400);
        }

        $payment = $this->paymentRepository->findById($paymentId);

        if ($payment === null) {
            return Response::json(['error' => 'Payment not found'], 404);
        }

        return Response::json([
            'id' => $payment->id,
            'amount' => $payment->amount->amount,
            'currency' => $payment->amount->currency->value,
            'status' => $payment->status->value,
            'method' => $payment->method->value,
            'gateway' => $payment->gateway,
            'created_at' => $payment->createdAt->format('c'),
        ]);
    }

    /**
     * GET /api/v1/payments
     *
     * List payments for the authenticated customer.
     */
    public function list(ServerRequestInterface $request): Response
    {
        /** @var mixed $rawCustomerId */
        $rawCustomerId = $request->getAttribute('user_id');
        $customerId = is_string($rawCustomerId) ? $rawCustomerId : '';

        if ($customerId === '') {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $payments = $this->paymentRepository->findByCustomer($customerId);

        $items = array_map(static fn($payment) => [
            'id' => $payment->id,
            'amount' => $payment->amount->amount,
            'currency' => $payment->amount->currency->value,
            'status' => $payment->status->value,
            'method' => $payment->method->value,
            'created_at' => $payment->createdAt->format('c'),
        ], $payments);

        return Response::json(['payments' => $items]);
    }
}
