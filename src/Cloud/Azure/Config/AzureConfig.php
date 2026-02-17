<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Azure\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function is_string;

/**
 * Configuration DTO for Microsoft Azure cloud services.
 */
#[Internal]
final readonly class AzureConfig
{
    public function __construct(
        public string $tenantId = '',
        public string $clientId = '',
        #[SensitiveParameter]
        public string $clientSecret = '',
        public ?string $endpoint = null,
        public ?string $accessToken = null,
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: is_string($data['tenant_id'] ?? null) ? $data['tenant_id'] : '',
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
            clientSecret: is_string($data['client_secret'] ?? null) ? $data['client_secret'] : '',
            endpoint: is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null,
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : null,
        );
    }

    /**
     * Resolve an access token from config or environment variables.
     */
    #[NoDiscard]
    public function resolveAccessToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        $envToken = getenv('AZURE_ACCESS_TOKEN') ?: '';

        if ($envToken !== '') {
            return $envToken;
        }

        return '';
    }

    /**
     * Resolve the tenant ID from config or environment.
     */
    #[NoDiscard]
    public function resolveTenantId(): string
    {
        if ($this->tenantId !== '') {
            return $this->tenantId;
        }

        return getenv('AZURE_TENANT_ID') ?: '';
    }

    /**
     * Prevent secrets from leaking in debug output.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'tenantId' => $this->tenantId,
            'clientId' => $this->clientId,
            'clientSecret' => '[REDACTED]',
            'endpoint' => $this->endpoint,
            'accessToken' => $this->accessToken !== null ? '[REDACTED]' : null,
        ];
    }
}
