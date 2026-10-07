<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\VerificationResult;
use Throwable;

use function base64_encode;
use function count;
use function error_log;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies subscriptions against the App Store Server API v2.
 *
 * Authenticates using a signed JWT (ES256) built from the app's
 * private key and issuer ID, then queries transaction history.
 *
 * Required config keys:
 *  - bundle_id: iOS app bundle identifier
 *  - issuer_id: App Store Connect API issuer ID
 *  - key_id: Private key identifier
 *  - private_key_path: Path to the AuthKey .p8 file
 *  - environment: "production" or "sandbox"
 */
#[Internal(reason: 'Store-specific verifier; use SubscriptionVerifierInterface')]
final readonly class AppStoreVerifier implements SubscriptionVerifierInterface
{
    private const string PRODUCTION_URL = 'https://api.storekit.itunes.apple.com';
    private const string SANDBOX_URL = 'https://api.storekit-sandbox.itunes.apple.com';

    /**
     * @param array{
     *     bundle_id: string,
     *     issuer_id: string,
     *     key_id: string,
     *     private_key_path: string,
     *     environment?: string,
     * } $config
     */
    public function __construct(
        private array $config,
    ) {}

    #[Override]
    public function verify(Store $store, string $purchaseToken): VerificationResult
    {
        if ($store !== Store::Apple) {
            return VerificationResult::invalid();
        }

        $bundleId = $this->config['bundle_id'] ?? '';

        if ($bundleId === '' || $purchaseToken === '') {
            return VerificationResult::invalid();
        }

        $jwt = $this->buildJwt();

        if ($jwt === null) {
            return VerificationResult::invalid();
        }

        $baseUrl = ($this->config['environment'] ?? 'production') === 'sandbox'
            ? self::SANDBOX_URL
            : self::PRODUCTION_URL;

        $url = "$baseUrl/inApps/v1/subscriptions/$purchaseToken";
        $responseBody = $this->httpGet($url, $jwt);

        if ($responseBody === null) {
            return VerificationResult::invalid();
        }

        return $this->parseResponse($responseBody);
    }

    /**
     * Build a signed JWT for App Store Server API authentication.
     *
     * Uses ES256 (ECDSA with P-256 and SHA-256) as required by Apple.
     */
    private function buildJwt(): ?string
    {
        $issuerId = $this->config['issuer_id'] ?? '';
        $keyId = $this->config['key_id'] ?? '';
        $privateKeyPath = $this->config['private_key_path'] ?? '';
        $bundleId = $this->config['bundle_id'] ?? '';

        if ($issuerId === '' || $keyId === '' || $privateKeyPath === '' || $bundleId === '') {
            return null;
        }

        if (!is_file($privateKeyPath)) {
            return null;
        }

        try {
            $privateKeyPem = file_get_contents($privateKeyPath);

            if ($privateKeyPem === false) {
                return null;
            }

            $now = time();
            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'ES256',
                'kid' => $keyId,
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR));

            $payload = $this->base64UrlEncode(json_encode([
                'iss' => $issuerId,
                'iat' => $now,
                'exp' => $now + 3600,
                'aud' => 'appstoreconnect-v1',
                'bid' => $bundleId,
            ], JSON_THROW_ON_ERROR));

            $signingInput = "$header.$payload";
            $signature = '';
            $key = openssl_pkey_get_private($privateKeyPem);

            if ($key === false) {
                return null;
            }

            $signed = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

            if (!$signed) {
                return null;
            }

            /** @var string $signature */
            return "$signingInput." . $this->base64UrlEncode($signature);
        } catch (Throwable $e) {
            // JWT signing failure is a configuration error (bad private
            // key, wrong key ID, expired certificate). The caller
            // already treats null as "verification failed", but we
            // must preserve operator visibility so the subscription
            // verification path doesn't silently stop working after
            // a key rotation. (H-2 audit response.)
            error_log(sprintf(
                '[pulsar.AppStoreVerifier] JWT signing failed: %s',
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Perform an authenticated GET request to the App Store Server API.
     */
    private function httpGet(string $url, string $jwt): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Bearer $jwt\r\n"
                        . "Accept: application/json\r\n",
                    'timeout' => 15,
                ],
            ]);

            $response = file_get_contents($url, false, $context);

            return $response !== false ? $response : null;
        } catch (Throwable $e) {
            // HTTP transport errors (Apple API down, DNS failure,
            // certificate expiry, timeout). Log before returning null
            // so operators can distinguish API outages from real
            // verification failures. (H-2 audit response.)
            error_log(sprintf(
                '[pulsar.AppStoreVerifier] App Store API request failed for %s: %s',
                $url,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Parse the App Store subscription status response.
     *
     * The response contains a `data` array with subscription group statuses.
     * Each group contains `lastTransactions` with signed renewal/transaction info.
     */
    private function parseResponse(string $responseBody): VerificationResult
    {
        try {
            $data = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return VerificationResult::invalid();
            }

            /** @var array<string, mixed> $data */

            /** @var list<array<string, mixed>> $subscriptionGroups */
            $subscriptionGroups = is_array($data['data'] ?? null) ? $data['data'] : [];

            if ($subscriptionGroups === []) {
                return VerificationResult::invalid();
            }

            $firstGroup = $subscriptionGroups[0];

            /** @var list<array<string, mixed>> $lastTransactions */
            $lastTransactions = is_array($firstGroup['lastTransactions'] ?? null)
                ? $firstGroup['lastTransactions']
                : [];

            if ($lastTransactions === []) {
                return VerificationResult::invalid();
            }

            $lastTransaction = $lastTransactions[0];
            /** @var mixed $rawStatus */
            $rawStatus = $lastTransaction['status'] ?? null;
            $status = is_int($rawStatus) ? $rawStatus : -1;

            // Status codes: 1=Active, 2=Expired, 3=BillingRetry, 4=GracePeriod, 5=Revoked
            $isValid = $status === 1 || $status === 4;

            // Decode the signed transaction info to get product/expiry details
            /** @var mixed $rawSignedTransactionInfo */
            $rawSignedTransactionInfo = $lastTransaction['signedTransactionInfo'] ?? null;
            $signedTransactionInfo = is_string($rawSignedTransactionInfo) ? $rawSignedTransactionInfo : '';

            $transactionInfo = $this->decodeSignedPayload($signedTransactionInfo);
            /** @var mixed $rawProductId */
            $rawProductId = $transactionInfo['productId'] ?? null;
            $productId = is_string($rawProductId) ? $rawProductId : '';

            /** @var mixed $rawExpiresDate */
            $rawExpiresDate = $transactionInfo['expiresDate'] ?? null;
            $expiresDateMs = is_int($rawExpiresDate) ? $rawExpiresDate : null;

            $expiresAt = $expiresDateMs !== null
                ? new DateTimeImmutable()->setTimestamp(intdiv($expiresDateMs, 1000))
                : null;

            $gracePeriodUntil = $status === 4 && $expiresAt !== null ? $expiresAt : null;

            // Auto-renew status from signed renewal info
            /** @var mixed $rawSignedRenewalInfo */
            $rawSignedRenewalInfo = $lastTransaction['signedRenewalInfo'] ?? null;
            $signedRenewalInfo = is_string($rawSignedRenewalInfo) ? $rawSignedRenewalInfo : '';

            $renewalInfo = $this->decodeSignedPayload($signedRenewalInfo);
            $autoRenewing = ($renewalInfo['autoRenewStatus'] ?? 0) === 1;

            return new VerificationResult(
                isValid: $isValid,
                expiresAt: $expiresAt,
                gracePeriodUntil: $gracePeriodUntil,
                productId: $productId,
                autoRenewing: $autoRenewing,
            );
        } catch (Throwable $e) {
            // Response parsing failure is usually a schema change on
            // Apple's side — operators need to know about it before the
            // renewal backlog piles up. (H-2 audit response.)
            error_log(sprintf(
                '[pulsar.AppStoreVerifier] App Store response parsing failed: %s',
                $e->getMessage(),
            ));

            return VerificationResult::invalid();
        }
    }

    /**
     * Decode a JWS signed payload from Apple (extract the claims without verification).
     *
     * In production, the signature SHOULD be verified against Apple's root CA chain.
     * This implementation extracts the payload for field parsing.
     *
     * @return array<string, mixed>
     */
    private function decodeSignedPayload(string $signedPayload): array
    {
        if ($signedPayload === '') {
            return [];
        }

        $parts = explode('.', $signedPayload);

        if (count($parts) !== 3) {
            return [];
        }

        try {
            $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);

            if ($payloadJson === false) {
                return [];
            }

            /** @var mixed $decoded */
            $decoded = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (Throwable $e) {
            // Signed payload decode failure: the claims cannot be
            // trusted, so return an empty claim set (safe default),
            // but log so a malformed JWS doesn't silently strip
            // fields from a valid response.
            error_log(sprintf(
                '[pulsar.AppStoreVerifier] signed-payload decode failed: %s',
                $e->getMessage(),
            ));

            return [];
        }
    }

    /**
     * URL-safe Base64 encoding (RFC 4648 Section 5).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
