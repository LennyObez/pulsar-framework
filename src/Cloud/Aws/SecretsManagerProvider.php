<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;

use function hash;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_replace;

use const JSON_THROW_ON_ERROR;

/**
 * AWS Secrets Manager provider for loading secrets via raw HTTP.
 *
 * Retrieves secret values from AWS Secrets Manager using the
 * GetSecretValue API with SigV4 signing. Supports both string
 * and binary secrets, with optional version stage selection.
 */
#[Internal]
final readonly class SecretsManagerProvider
{
    public function __construct(
        private AwsConfig $config,
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Retrieve a secret value by ID or ARN.
     *
     * @param string      $secretId    The secret name or ARN
     * @param string|null $versionStage The version stage (default: AWSCURRENT)
     *
     * @throws CloudException If the secret cannot be retrieved
     */
    #[NoDiscard]
    public function getSecret(string $secretId, ?string $versionStage = null): string
    {
        $endpoint = $this->config->endpoint
            ?? sprintf('https://secretsmanager.%s.amazonaws.com', $this->config->region);

        /** @var array<string, string> $requestPayload */
        $requestPayload = ['SecretId' => $secretId];

        if ($versionStage !== null) {
            $requestPayload['VersionStage'] = $versionStage;
        }

        $body = json_encode($requestPayload, JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $body);
        $host = str_replace(['https://', 'http://'], '', $endpoint);

        $headers = [
            'Host' => $host,
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => 'secretsmanager.GetSecretValue',
        ];

        $signer = $this->getSigner();
        $signedHeaders = $signer->sign('POST', '/', '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('POST', $endpoint, $signedHeaders, $body);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('secretsmanager', $e->getMessage(), $e);
        }

        if ($response->statusCode === 404) {
            throw CloudException::secretNotFound('AWS Secrets Manager', $secretId);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'secretsmanager',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

        /** @var array{SecretString?: string, SecretBinary?: string} $decoded */
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['SecretString'])) {
            return $decoded['SecretString'];
        }

        if (isset($decoded['SecretBinary'])) {
            return $decoded['SecretBinary'];
        }

        throw CloudException::requestFailed('secretsmanager', 'Response contained neither SecretString nor SecretBinary');
    }

    /**
     * Retrieve multiple secrets as a key-value map.
     *
     * @param list<string> $secretIds List of secret names or ARNs
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

    private function getSigner(): AwsSigner
    {
        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw CloudException::authenticationFailed('aws', 'AWS credentials not configured for Secrets Manager');
        }

        return new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            'secretsmanager',
        );
    }
}
