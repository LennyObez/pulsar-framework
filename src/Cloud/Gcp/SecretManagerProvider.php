<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Gcp;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Cloud\Gcp\Config\GcpConfig;

use function base64_decode;
use function json_decode;
use function rawurlencode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * GCP Secret Manager provider for loading secrets via REST API.
 *
 * Retrieves secret values from Google Cloud Secret Manager using
 * the AccessSecretVersion API with OAuth2 bearer tokens.
 */
#[Internal]
final readonly class SecretManagerProvider
{
    private const string API_BASE = 'https://secretmanager.googleapis.com/v1';

    public function __construct(
        private GcpConfig $config,
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Retrieve a secret value by name.
     *
     * @param string $secretId The secret name
     * @param string $version  The version (default: "latest")
     *
     * @throws CloudException If the secret cannot be retrieved
     */
    #[NoDiscard]
    public function getSecret(string $secretId, string $version = 'latest'): string
    {
        $projectId = $this->config->resolveProjectId();

        $url = sprintf(
            '%s/projects/%s/secrets/%s/versions/%s:access',
            self::API_BASE,
            $projectId,
            rawurlencode($secretId),
            rawurlencode($version),
        );

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException $e) {
            throw CloudException::requestFailed('secretmanager', $e->getMessage(), $e);
        }

        if ($response->statusCode === 404) {
            throw CloudException::secretNotFound('GCP Secret Manager', $secretId);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'secretmanager',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

        /** @var array{payload?: array{data?: string}} $decoded */
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $data = $decoded['payload']['data'] ?? null;

        if ($data === null) {
            throw CloudException::requestFailed('secretmanager', 'Response payload missing data field');
        }

        $decoded_data = base64_decode($data, true);

        if ($decoded_data === false) {
            throw CloudException::requestFailed('secretmanager', 'Failed to decode secret data');
        }

        return $decoded_data;
    }

    /**
     * Retrieve multiple secrets as a key-value map.
     *
     * @param list<string> $secretIds List of secret names
     * @return array<string, string> Secret ID => secret value
     *
     * @throws CloudException If any secret cannot be retrieved
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function getSecrets(array $secretIds): array
    {
        $secrets = [];

        foreach ($secretIds as $secretId) {
            $secrets[$secretId] = $this->getSecret($secretId);
        }

        return $secrets;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw CloudException::authenticationFailed('gcp', 'GCP access token not configured for Secret Manager');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
