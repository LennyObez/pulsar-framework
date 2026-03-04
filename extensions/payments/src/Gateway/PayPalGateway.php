<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PayPalConfig;
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
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * PayPal payment provider implementation.
 *
 * Uses the PayPal Orders v2 API with raw HTTP requests.
 */
#[Internal]
final readonly class PayPalGateway implements PaymentProviderInterface
{
    public function __construct(
        private PayPalConfig $config,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'paypal';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        $baseUrl = $this->apiBaseUrl();
        $accessToken = $this->authenticate();

        $body = json_encode([
            'intent' => 'AUTHORIZE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $amount->currency->value,
                    'value' => $amount->format(),
                ],
                'reference_id' => $idempotencyKey,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = $this->httpRequest('POST', "$baseUrl/v2/checkout/orders", $body, $accessToken, $idempotencyKey);

        /** @var string $orderId */
        $orderId = $response['id'] ?? '';

        return new PaymentIntent(
            id: $orderId,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $baseUrl = $this->apiBaseUrl();
        $accessToken = $this->authenticate();

        $response = $this->httpRequest('POST', "$baseUrl/v2/checkout/orders/$intentId/capture", '{}', $accessToken, $idempotencyKey);

        /** @var list<array<string, mixed>> $purchaseUnits */
        $purchaseUnits = is_array($response['purchase_units'] ?? null) ? $response['purchase_units'] : [];
        $firstUnit = $purchaseUnits[0] ?? [];

        /** @var array<string, mixed> $payments */
        $payments = is_array($firstUnit['payments'] ?? null) ? $firstUnit['payments'] : [];
        /** @var list<array<string, mixed>> $captures */
        $captures = is_array($payments['captures'] ?? null) ? $payments['captures'] : [];
        $firstCapture = $captures[0] ?? [];

        $captureIdVal = $firstCapture['id'] ?? null;
        $captureId = is_string($captureIdVal) ? $captureIdVal : $intentId;

        /** @var array<string, mixed> $captureAmount */
        $captureAmount = is_array($firstCapture['amount'] ?? null) ? $firstCapture['amount'] : [];
        $valueRaw = $captureAmount['value'] ?? null;
        $value = is_string($valueRaw) ? $valueRaw : '0';
        $currRaw = $captureAmount['currency_code'] ?? null;
        $currencyCode = is_string($currRaw) ? strtoupper($currRaw) : 'USD';

        $currency = Currency::from($currencyCode);
        $minorUnits = (int) round((float) $value * (10 ** $currency->minorDigits()));

        return new Charge(
            id: $captureId,
            intentId: $intentId,
            amount: Money::of($minorUnits, $currency),
            status: ChargeStatus::Succeeded,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        // PayPal: voiding an authorized order
        return new PaymentIntent(
            id: $intentId,
            amount: Money::of(0, Currency::USD),
            status: PaymentIntentStatus::Cancelled,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $baseUrl = $this->apiBaseUrl();
        $accessToken = $this->authenticate();

        $body = '{}';

        if ($amount !== null) {
            $body = json_encode([
                'amount' => [
                    'value' => $amount->format(),
                    'currency_code' => $amount->currency->value,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->httpRequest('POST', "$baseUrl/v2/payments/captures/$chargeId/refund", $body, $accessToken, $idempotencyKey);

        /** @var string $refundId */
        $refundId = $response['id'] ?? '';

        $refundAmount = $amount ?? Money::of(0, Currency::USD);

        return new Refund(
            id: $refundId,
            chargeId: $chargeId,
            amount: $refundAmount,
            status: RefundStatus::Succeeded,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function getIntent(string $intentId): PaymentIntent
    {
        throw PaymentException::notFound('PaymentIntent', $intentId);
    }

    #[Override]
    public function getCharge(string $chargeId): Charge
    {
        throw PaymentException::notFound('Charge', $chargeId);
    }

    #[Override]
    public function getRefund(string $refundId): Refund
    {
        throw PaymentException::notFound('Refund', $refundId);
    }

    private function apiBaseUrl(): string
    {
        return $this->config->sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * Obtain an OAuth2 access token from PayPal.
     *
     * @throws PaymentProviderException
     */
    private function authenticate(): string
    {
        $baseUrl = $this->apiBaseUrl();

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Authorization: Basic ' . base64_encode("{$this->config->clientId}:{$this->config->clientSecret}") . "\r\n"
                        . "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => 'grant_type=client_credentials',
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $response = file_get_contents("$baseUrl/v1/oauth2/token", false, $context);

            if ($response === false) {
                throw PaymentProviderException::networkError('Failed to authenticate with PayPal');
            }

            /** @var array{access_token?: string} $data */
            $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
            $token = $data['access_token'] ?? '';

            if ($token === '') {
                throw PaymentProviderException::providerError('PayPal: failed to obtain access token');
            }

            return $token;
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw PaymentProviderException::providerError('PayPal auth error: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    private function httpRequest(string $method, string $url, string $body, string $accessToken, ?string $idempotencyKey = null): array
    {
        $headers = [
            "Authorization: Bearer $accessToken",
            'Content-Type: application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers[] = "PayPal-Request-Id: $idempotencyKey";
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $body,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw PaymentProviderException::networkError('Failed to connect to PayPal API');
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);

            if (isset($decoded['error'])) {
                $message = is_string($decoded['error_description'] ?? null)
                    ? $decoded['error_description']
                    : 'Unknown PayPal error';

                throw PaymentProviderException::providerError("PayPal: $message");
            }

            return $decoded;
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw PaymentProviderException::providerError('PayPal API error: ' . $e->getMessage(), $e);
        }
    }
}
