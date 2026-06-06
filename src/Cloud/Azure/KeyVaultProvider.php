<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Azure;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;

use function json_decode;
use function rawurlencode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Azure Key Vault provider for loading secrets via REST API.
 *
 * Retrieves secret values from Azure Key Vault using the
 * Secrets API with OAuth2 bearer tokens.
 */
#[Internal]
final readonly class KeyVaultProvider
{
    public function __construct(
        private AzureConfig $config,
        private string $vaultName,
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Retrieve a secret value by name.
     *
     * @param string $secretName The secret name
     * @param string $version    The secret version (empty for latest)
     *
     * @throws CloudException If the secret cannot be retrieved
     */
    #[NoDiscard]
    public function getSecret(string $secretName, string $version = ''): string
    {
        $baseUrl = $this->config->endpoint
            ?? sprintf('https://%s.vault.azure.net', $this->vaultName);

        $path = $version !== ''
            ? sprintf('/secrets/%s/%s', rawurlencode($secretName), rawurlencode($version))
            : sprintf('/secrets/%s', rawurlencode($secretName));

        $url = $baseUrl . $path . '?api-version=7.4';

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException $e) {
            throw CloudException::requestFailed('keyvault', $e->getMessage(), $e);
        }

        if ($response->statusCode === 404) {
            throw CloudException::secretNotFound('Azure Key Vault', $secretName);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'keyvault',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

        /** @var array{value?: string} $decoded */
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        if (!isset($decoded['value'])) {
            throw CloudException::requestFailed('keyvault', 'Response missing value field');
        }

        return $decoded['value'];
    }

    /**
     * Retrieve multiple secrets as a key-value map.
     *
     * @param list<string> $secretNames List of secret names
     * @return array<string, string> Secret name => secret value
     *
     * @throws CloudException If any secret cannot be retrieved
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function getSecrets(array $secretNames): array
    {
        $secrets = [];

        foreach ($secretNames as $name) {
            $secrets[$name] = $this->getSecret($name);
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
            throw CloudException::authenticationFailed('azure', 'Azure access token not configured for Key Vault');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
