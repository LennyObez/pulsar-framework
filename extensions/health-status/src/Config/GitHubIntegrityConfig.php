<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function getenv;
use function is_string;

/**
 * Configuration for GitHub-based integrity verification.
 *
 * The token is read from the GITHUB_INTEGRITY_TOKEN environment variable
 * when not explicitly provided in the configuration array. Tokens are
 * never stored in configuration files.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $explicitToken = $data['token'] ?? null;
        $token = is_string($explicitToken) ? $explicitToken : self::tokenFromEnv();

        /** @var bool|string $enabled */
        $enabled = $data['enabled'] ?? false;
        /** @var string $repository */
        $repository = $data['repository'] ?? '';
        /** @var string $branch */
        $branch = $data['branch'] ?? 'main';
        /** @var int|string $timeout */
        $timeout = $data['timeout_seconds'] ?? 15;
        /** @var int|string $rateLimit */
        $rateLimit = $data['rate_limit_requests_per_hour'] ?? 30;
        /** @var string $suffix */
        $suffix = $data['modified_file_suffix'] ?? '.modified-backup';

        return new self(
            enabled: (bool) $enabled,
            repository: (string) $repository,
            branch: (string) $branch,
            token: $token,
            timeoutSeconds: (int) $timeout,
            rateLimitRequestsPerHour: (int) $rateLimit,
            modifiedFileSuffix: (string) $suffix,
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
