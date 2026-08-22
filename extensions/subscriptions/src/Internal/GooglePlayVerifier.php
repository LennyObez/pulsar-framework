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

use function error_log;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies subscriptions against the Google Play Developer API v3.
 *
 * Authenticates via a service account and calls
 * `purchases.subscriptionsv2.get` to validate purchase tokens.
 *
 * Required config keys:
 *  - package_name: Android app package name
 *  - service_account_json: Path to the service account credentials file
 *  - api_base_url: Google API base URL (default: https://androidpublisher.googleapis.com)
 */
#[Internal(reason: 'Store-specific verifier; use SubscriptionVerifierInterface')]
final readonly class GooglePlayVerifier implements SubscriptionVerifierInterface
{
    /**
     * @param array{
     *     package_name: string,
     *     service_account_json: string,
     *     api_base_url?: string,
     * } $config
     */
    public function __construct(
        private array $config,
    ) {}

    #[Override]
    public function verify(Store $store, string $purchaseToken): VerificationResult
    {
        if ($store !== Store::Google) {
            return VerificationResult::invalid();
        }

        $packageName = $this->config['package_name'] ?? '';
        $baseUrl = $this->config['api_base_url'] ?? 'https://androidpublisher.googleapis.com';

        if ($packageName === '' || $purchaseToken === '') {
            return VerificationResult::invalid();
        }

        $accessToken = $this->obtainAccessToken();

        if ($accessToken === null) {
            return VerificationResult::invalid();
        }

        $url = "$baseUrl/androidpublisher/v3/applications/$packageName"
            . "/purchases/subscriptionsv2/tokens/$purchaseToken";

        $responseBody = $this->httpGet($url, $accessToken);

        if ($responseBody === null) {
            return VerificationResult::invalid();
        }

        return $this->parseResponse($responseBody);
    }

    /**
     * Obtain an OAuth2 access token from the service account credentials.
     *
     * Uses the service account JSON key to create a signed JWT, then
     * exchanges it for a short-lived access token via Google's OAuth2 endpoint.
     */
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
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = base64_encode(json_encode([
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
            $jwt = "$signingInput." . base64_encode($signature);

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
        } catch (Throwable $e) {
            // OAuth2 token exchange failure: usually misconfigured
            // service account JSON or clock skew. (H-3 audit response.)
            error_log(sprintf(
                '[pulsar.GooglePlayVerifier] OAuth2 token exchange failed: %s',
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Perform an authenticated GET request to the Google API.
     */
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
        } catch (Throwable $e) {
            error_log(sprintf(
                '[pulsar.GooglePlayVerifier] Play Developer API request failed for %s: %s',
                $url,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Parse the Google Play subscriptions v2 response.
     *
     * @param string $responseBody Raw JSON response
     */
    private function parseResponse(string $responseBody): VerificationResult
    {
        try {
            $data = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return VerificationResult::invalid();
            }

            /** @var array<string, mixed> $data */

            // subscriptionState: SUBSCRIPTION_STATE_ACTIVE, etc.
            /** @var mixed $rawState */
            $rawState = $data['subscriptionState'] ?? null;
            $state = is_string($rawState) ? $rawState : '';
            $isValid = $state === 'SUBSCRIPTION_STATE_ACTIVE'
                || $state === 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD';

            // Line items contain expiry and product info
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

            // Grace period
            $gracePeriodUntil = $state === 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' && $expiresAt !== null
                ? $expiresAt
                : null;

            return new VerificationResult(
                isValid: $isValid,
                expiresAt: $expiresAt,
                gracePeriodUntil: $gracePeriodUntil,
                productId: $productId,
                autoRenewing: $autoRenewing,
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[pulsar.GooglePlayVerifier] Play Developer response parsing failed: %s',
                $e->getMessage(),
            ));

            return VerificationResult::invalid();
        }
    }
}
