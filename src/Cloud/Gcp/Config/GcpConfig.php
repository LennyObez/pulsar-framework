<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Gcp\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Support\Coerce;
use SensitiveParameter;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Configuration DTO for Google Cloud Platform services.
 */
#[Internal]
final readonly class GcpConfig
{
    public function __construct(
        public string $projectId = '',
        public string $credentialsPath = '',
        public ?string $endpoint = null,
        #[SensitiveParameter]
        public ?string $accessToken = null,
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            projectId: Coerce::string($data['project_id'] ?? null),
            credentialsPath: Coerce::string($data['credentials_path'] ?? null),
            endpoint: Coerce::nullableString($data['endpoint'] ?? null),
            accessToken: Coerce::nullableString($data['access_token'] ?? null),
        );
    }

    /**
     * Resolve the project ID from config or credentials file.
     */
    #[NoDiscard]
    public function resolveProjectId(): string
    {
        if ($this->projectId !== '') {
            return $this->projectId;
        }

        $envProjectId = Environment::read('GCLOUD_PROJECT') ?: Environment::read('GOOGLE_CLOUD_PROJECT');

        if ($envProjectId !== '') {
            return $envProjectId;
        }

        $credentials = $this->loadCredentials();

        return $credentials['project_id'] ?? '';
    }

    /**
     * Resolve an access token from config, environment, or service account.
     */
    #[NoDiscard]
    public function resolveAccessToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        return Environment::read('GOOGLE_ACCESS_TOKEN');
    }

    /**
     * Load credentials from the JSON key file.
     *
     * @return array<string, string>
     */
    #[NoDiscard]
    public function loadCredentials(): array
    {
        $path = $this->credentialsPath !== '' ? $this->credentialsPath : Environment::read('GOOGLE_APPLICATION_CREDENTIALS');

        if ($path === '' || !file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [];
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Prevent secrets from leaking in debug output.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'projectId' => $this->projectId,
            'credentialsPath' => $this->credentialsPath,
            'endpoint' => $this->endpoint,
            'accessToken' => $this->accessToken !== null ? '[REDACTED]' : null,
        ];
    }
}
