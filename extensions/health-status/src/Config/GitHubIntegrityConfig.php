<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function getenv;

/**
 * Configuration for GitHub-based integrity verification.
 *
 * The token is read from the GITHUB_INTEGRITY_TOKEN environment variable
 * when not explicitly provided in the configuration array. Tokens are
 * never stored in configuration files.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GitHubIntegrityConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $repository = '',
        public string $branch = 'main',
        public ?string $token = null,
        public int $timeoutSeconds = 15,
        public int $rateLimitRequestsPerHour = 30,
        public string $modifiedFileSuffix = '.modified-backup',
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     repository?: string,
     *     branch?: string,
     *     token?: string|null,
     *     timeout_seconds?: int,
     *     rate_limit_requests_per_hour?: int,
     *     modified_file_suffix?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            repository: $data['repository'] ?? '',
            branch: $data['branch'] ?? 'main',
            token: $data['token'] ?? self::tokenFromEnv(),
            timeoutSeconds: $data['timeout_seconds'] ?? 15,
            rateLimitRequestsPerHour: $data['rate_limit_requests_per_hour'] ?? 30,
            modifiedFileSuffix: $data['modified_file_suffix'] ?? '.modified-backup',
        );
    }

    private static function tokenFromEnv(): ?string
    {
        $value = getenv('GITHUB_INTEGRITY_TOKEN');

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
