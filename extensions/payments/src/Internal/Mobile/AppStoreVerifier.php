<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Mobile;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\MobileVerifierInterface;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\MobileVerificationResult;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Throwable;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies subscriptions against the App Store Server API v2.
 *
 * Authenticates using a signed JWT (ES256) built from the app's
 * private key and issuer ID, then queries transaction history.
 *
 * Migrated from the subscriptions extension into the unified payments module.
 */
#[Internal]
final readonly class AppStoreVerifier implements MobileVerifierInterface
{
    private const string PRODUCTION_URL = 'https://api.storekit.itunes.apple.com';
    private const string SANDBOX_URL = 'https://api.storekit-sandbox.itunes.apple.com';

    /**
     * @param array{bundle_id: string, issuer_id: string, key_id: string, private_key_path: string, environment?: string} $config
     */
    public function __construct(
        private array $config,
    ) {}

    #[Override]
    public function verify(MobileStore $store, string $purchaseToken): MobileVerificationResult
    {
        if ($store !== MobileStore::Apple) {
            return MobileVerificationResult::invalid();
        }

        $bundleId = $this->config['bundle_id'] ?? '';

        if ($bundleId === '' || $purchaseToken === '') {
            return MobileVerificationResult::invalid();
        }

        $jwt = $this->buildJwt();

        if ($jwt === null) {
            return MobileVerificationResult::invalid();
        }

        $baseUrl = ($this->config['environment'] ?? 'production') === 'sandbox'
            ? self::SANDBOX_URL
            : self::PRODUCTION_URL;

        $url = "$baseUrl/inApps/v1/subscriptions/$purchaseToken";
        $responseBody = $this->httpGet($url, $jwt);

        if ($responseBody === null) {
            return MobileVerificationResult::invalid();
        }

        return $this->parseResponse($responseBody);
    }

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
        } catch (Throwable) {
            return null;
        }
    }

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
        } catch (Throwable) {
            return null;
        }
    }

    private function parseResponse(string $responseBody): MobileVerificationResult
    {
        try {
            $data = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return MobileVerificationResult::invalid();
            }

            /** @var array<string, mixed> $data */

            /** @var list<array<string, mixed>> $subscriptionGroups */
            $subscriptionGroups = is_array($data['data'] ?? null) ? $data['data'] : [];

            if ($subscriptionGroups === []) {
                return MobileVerificationResult::invalid();
            }

            $firstGroup = $subscriptionGroups[0];

            /** @var list<array<string, mixed>> $lastTransactions */
            $lastTransactions = is_array($firstGroup['lastTransactions'] ?? null)
                ? $firstGroup['lastTransactions']
                : [];

            if ($lastTransactions === []) {
                return MobileVerificationResult::invalid();
            }

            $lastTransaction = $lastTransactions[0];
            /** @var mixed $rawStatus */
            $rawStatus = $lastTransaction['status'] ?? null;
            $status = is_int($rawStatus) ? $rawStatus : -1;

            $isValid = $status === 1 || $status === 4;

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

            /** @var mixed $rawSignedRenewalInfo */
            $rawSignedRenewalInfo = $lastTransaction['signedRenewalInfo'] ?? null;
            $signedRenewalInfo = is_string($rawSignedRenewalInfo) ? $rawSignedRenewalInfo : '';

            $renewalInfo = $this->decodeSignedPayload($signedRenewalInfo);
            $autoRenewing = ($renewalInfo['autoRenewStatus'] ?? 0) === 1;

            return new MobileVerificationResult(
                isValid: $isValid,
                expiresAt: $expiresAt,
                gracePeriodUntil: $gracePeriodUntil,
                productId: $productId,
                autoRenewing: $autoRenewing,
            );
        } catch (Throwable) {
            return MobileVerificationResult::invalid();
        }
    }

    /**
     * Decode and verify a signed payload (JWS) from Apple's Server API.
     *
     * Verifies the ES256 signature using the x5c certificate chain before
     * returning the decoded payload. Returns an empty array on failure.
     *
     * @return array<string, mixed>
     */
    private function decodeSignedPayload(string $signedPayload): array
    {
        if ($signedPayload === '') {
            return [];
        }

        try {
            return JwsVerifier::verifyAndDecode($signedPayload);
        } catch (PaymentException) {
            return [];
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
