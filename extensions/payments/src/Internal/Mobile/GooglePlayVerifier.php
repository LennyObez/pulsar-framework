<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Mobile;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\MobileVerifierInterface;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\MobileVerificationResult;
use Throwable;

use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function strtr;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies subscriptions against the Google Play Developer API v3.
 *
 * Authenticates via a service account and calls
 * purchases.subscriptionsv2.get to validate purchase tokens.
 *
 * Migrated from the subscriptions extension into the unified payments module.
 */
#[Internal]
final readonly class GooglePlayVerifier implements MobileVerifierInterface
{
    /**
     * @param array{package_name: string, service_account_json: string, api_base_url?: string} $config
     */
    public function __construct(
        private array $config,
    ) {}

    #[Override]
    public function verify(MobileStore $store, string $purchaseToken): MobileVerificationResult
    {
        if ($store !== MobileStore::Google) {
            return MobileVerificationResult::invalid();
        }

        $packageName = $this->config['package_name'] ?? '';
        $baseUrl = $this->config['api_base_url'] ?? 'https://androidpublisher.googleapis.com';

        if ($packageName === '' || $purchaseToken === '') {
            return MobileVerificationResult::invalid();
        }

        $accessToken = $this->obtainAccessToken();

        if ($accessToken === null) {
            return MobileVerificationResult::invalid();
        }

        $url = "$baseUrl/androidpublisher/v3/applications/$packageName"
            . "/purchases/subscriptionsv2/tokens/$purchaseToken";

        $responseBody = $this->httpGet($url, $accessToken);

        if ($responseBody === null) {
            return MobileVerificationResult::invalid();
        }

        return $this->parseResponse($responseBody);
    }

    private function obtainAccessToken(): ?string
    {
        $credentialsPath = $this->config['service_account_json'] ?? '';

        if ($credentialsPath === '' || !is_file($credentialsPath)) {
            return null;
        }

        try {
            $credentialsJson = file_get_contents($credentialsPath);

            if ($credentialsJson === false) {
                return null;
            }

            /** @var array{client_email?: string, private_key?: string, token_uri?: string} $credentials */
            $credentials = json_decode($credentialsJson, true, 32, JSON_THROW_ON_ERROR);

            $clientEmail = $credentials['client_email'] ?? '';
            $privateKey = $credentials['private_key'] ?? '';
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            if ($clientEmail === '' || $privateKey === '') {
                return null;
            }

            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $signingInput = "$header.$claims";
            $signature = '';
            $key = openssl_pkey_get_private($privateKey);

            if ($key === false) {
                return null;
            }

            $signed = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

            if (!$signed) {
                return null;
            }

            /** @var string $signature */
            $jwt = "$signingInput." . $this->base64UrlEncode($signature);

            $postData = http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postData,
                    'timeout' => 10,
                ],
            ]);

            $tokenResponse = file_get_contents($tokenUri, false, $context);

            if ($tokenResponse === false) {
                return null;
            }

            /** @var array{access_token?: string} $tokenData */
            $tokenData = json_decode($tokenResponse, true, 16, JSON_THROW_ON_ERROR);

            $token = $tokenData['access_token'] ?? '';

            return $token !== '' ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function httpGet(string $url, string $accessToken): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Bearer $accessToken\r\n"
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
            $data = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return MobileVerificationResult::invalid();
            }

            /** @var array<string, mixed> $data */
            /** @var mixed $rawState */
            $rawState = $data['subscriptionState'] ?? null;
            $state = is_string($rawState) ? $rawState : '';
            $isValid = $state === 'SUBSCRIPTION_STATE_ACTIVE'
                || $state === 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD';

            /** @var mixed $rawLineItems */
            $rawLineItems = $data['lineItems'] ?? null;
            /** @var list<array<string, mixed>> $lineItems */
            $lineItems = is_array($rawLineItems) ? $rawLineItems : [];
            $firstItem = $lineItems[0] ?? [];

            /** @var mixed $rawExpiry */
            $rawExpiry = $firstItem['expiryTime'] ?? null;
            $expiryTimeMillis = is_string($rawExpiry) ? $rawExpiry : null;

            $expiresAt = $expiryTimeMillis !== null
                ? new DateTimeImmutable($expiryTimeMillis)
                : null;

            /** @var mixed $rawProductId */
            $rawProductId = $firstItem['productId'] ?? null;
            $productId = is_string($rawProductId) ? $rawProductId : '';

            /** @var array<string, mixed> $autoRenewData */
            $autoRenewData = is_array($firstItem['autoRenewingPlan'] ?? null)
                ? $firstItem['autoRenewingPlan']
                : [];

            $autoRenewing = ($autoRenewData['autoRenewEnabled'] ?? false) === true;

            $gracePeriodUntil = $state === 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' && $expiresAt !== null
                ? $expiresAt
                : null;

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

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
