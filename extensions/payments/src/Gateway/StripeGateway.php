<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Throwable;

use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Stripe payment provider implementation.
 *
 * Communicates with the Stripe API using raw HTTP requests (no SDK dependency).
 * All card numbers are tokenized by Stripe.js on the client side --
 * raw card data never touches the server (PCI-DSS v4.0.1 compliant).
 */
#[Internal]
final readonly class StripeGateway implements PaymentProviderInterface
{
    private const string API_BASE = 'https://api.stripe.com/v1';

    public function __construct(
        private StripeConfig $config,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'stripe';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        $params = [
            'amount' => (string) $amount->amount,
            'currency' => strtolower($amount->currency->value),
            'capture_method' => 'manual',
        ];

        foreach ($metadata as $key => $value) {
            $params["metadata[$key]"] = is_scalar($value) ? (string) $value : '';
        }

        $response = $this->request('POST', '/payment_intents', $params, $idempotencyKey);

        /** @var string $id */
        $id = $response['id'] ?? '';
        /** @var string $status */
        $status = $response['status'] ?? 'requires_payment_method';

        return new PaymentIntent(
            id: $id,
            amount: $amount,
            status: self::mapIntentStatus($status),
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $response = $this->request('POST', "/payment_intents/$intentId/capture", [], $idempotencyKey);

        /** @var array<string, mixed> $latestCharge */
        $latestCharge = is_array($response['latest_charge'] ?? null) ? $response['latest_charge'] : [];

        $chargeId = self::strVal($latestCharge, 'id', self::strVal($response, 'id', ''));
        $capturedAmount = self::intVal($response, 'amount', 0);
        $currency = self::currencyStr($response, 'currency', 'USD');

        return new Charge(
            id: $chargeId,
            intentId: $intentId,
            amount: Money::of($capturedAmount, Currency::from($currency)),
            status: ChargeStatus::Succeeded,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        $response = $this->request('POST', "/payment_intents/$intentId/cancel", [], $idempotencyKey);

        $amount = self::intVal($response, 'amount', 0);
        $currency = self::currencyStr($response, 'currency', 'USD');

        return new PaymentIntent(
            id: $intentId,
            amount: Money::of($amount, Currency::from($currency)),
            status: PaymentIntentStatus::Cancelled,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $params = ['charge' => $chargeId];

        if ($amount !== null) {
            $params['amount'] = (string) $amount->amount;
        }

        $response = $this->request('POST', '/refunds', $params, $idempotencyKey);

        $refundId = self::strVal($response, 'id', '');
        $refundAmount = self::intVal($response, 'amount', 0);
        $refundCurrency = self::currencyStr($response, 'currency', 'USD');
        $refundStatus = self::strVal($response, 'status', 'pending');

        return new Refund(
            id: $refundId,
            chargeId: $chargeId,
            amount: Money::of($refundAmount, Currency::from($refundCurrency)),
            status: $refundStatus === 'succeeded' ? RefundStatus::Succeeded : RefundStatus::Pending,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function getIntent(string $intentId): PaymentIntent
    {
        $response = $this->request('GET', "/payment_intents/$intentId");

        $amount = self::intVal($response, 'amount', 0);
        $currency = self::currencyStr($response, 'currency', 'USD');
        $status = self::strVal($response, 'status', 'requires_payment_method');
        $created = self::intVal($response, 'created', 0);

        return new PaymentIntent(
            id: $intentId,
            amount: Money::of($amount, Currency::from($currency)),
            status: self::mapIntentStatus($status),
            provider: $this->name(),
            idempotencyKey: '',
            createdAt: new DateTimeImmutable('@' . $created),
        );
    }

    #[Override]
    public function getCharge(string $chargeId): Charge
    {
        $response = $this->request('GET', "/charges/$chargeId");

        $intentId = self::strVal($response, 'payment_intent', '');
        $amount = self::intVal($response, 'amount', 0);
        $currency = self::currencyStr($response, 'currency', 'USD');
        $status = self::strVal($response, 'status', 'pending');
        $created = self::intVal($response, 'created', 0);

        return new Charge(
            id: $chargeId,
            intentId: $intentId,
            amount: Money::of($amount, Currency::from($currency)),
            status: $status === 'succeeded' ? ChargeStatus::Succeeded : ChargeStatus::Pending,
            provider: $this->name(),
            createdAt: new DateTimeImmutable('@' . $created),
        );
    }

    #[Override]
    public function getRefund(string $refundId): Refund
    {
        $response = $this->request('GET', "/refunds/$refundId");

        $chargeId = self::strVal($response, 'charge', '');
        $amount = self::intVal($response, 'amount', 0);
        $currency = self::currencyStr($response, 'currency', 'USD');
        $status = self::strVal($response, 'status', 'pending');
        $created = self::intVal($response, 'created', 0);

        return new Refund(
            id: $refundId,
            chargeId: $chargeId,
            amount: Money::of($amount, Currency::from($currency)),
            status: $status === 'succeeded' ? RefundStatus::Succeeded : RefundStatus::Pending,
            provider: $this->name(),
            createdAt: new DateTimeImmutable('@' . $created),
        );
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $url = self::API_BASE . $path;

        $headers = [
            "Authorization: Bearer {$this->config->secretKey}",
            "Stripe-Version: {$this->config->apiVersion}",
            'Content-Type: application/x-www-form-urlencoded',
        ];

        if ($idempotencyKey !== null) {
            $headers[] = "Idempotency-Key: $idempotencyKey";
        }

        $headerString = implode("\r\n", $headers) . "\r\n";

        $options = [
            'http' => [
                'method' => $method,
                'header' => $headerString,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];

        if ($method === 'POST' && $params !== []) {
            $options['http']['content'] = http_build_query($params);
        }

        try {
            $context = stream_context_create($options);

            if ($method === 'GET' && $params !== []) {
                $url .= '?' . http_build_query($params);
            }

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw PaymentProviderException::networkError('Failed to connect to Stripe API');
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);

            if (isset($decoded['error'])) {
                /** @var array<string, mixed> $error */
                $error = $decoded['error'];
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown Stripe error';
                $type = is_string($error['type'] ?? null) ? $error['type'] : 'api_error';

                if ($type === 'card_error') {
                    throw PaymentProviderException::declined($message);
                }

                if ($type === 'rate_limit_error') {
                    throw PaymentProviderException::rateLimited($message);
                }

                throw PaymentProviderException::providerError("Stripe: $message");
            }

            return $decoded;
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw PaymentProviderException::providerError('Stripe API error: ' . $e->getMessage(), $e);
        }
    }

    private static function mapIntentStatus(string $stripeStatus): PaymentIntentStatus
    {
        return match ($stripeStatus) {
            'succeeded', 'requires_capture' => PaymentIntentStatus::Captured,
            'canceled' => PaymentIntentStatus::Cancelled,
            default => PaymentIntentStatus::Created,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function strVal(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intVal(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function currencyStr(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? strtoupper($value) : $default;
    }
}
